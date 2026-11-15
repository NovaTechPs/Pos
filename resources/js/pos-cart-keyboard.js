(function() {
    if (window.__posCartKeyboardNavigation) {
        return;
    }
    window.__posCartKeyboardNavigation = true;

    const FIELD_SELECTOR = '[data-pos-field]';
    const BARCODE_SELECTOR = '[data-pos-barcode-input]';
    const barcodeQueue = [];
    let isProcessingBarcodeQueue = false;
    let externalBarcodeBuffer = '';
    let externalBarcodeLastKeyAt = 0;
    let externalBarcodeCaptured = false;
    let externalBarcodeTarget = null;
    let externalBarcodeOriginalValue = null;
    // A scanner types a whole barcode far faster than a person can, even into a number field.
    const NUMERIC_FIELD_SELECTOR = 'input[type="number"], input[data-pos-field]';
    const FIELD_SCAN_MIN_LENGTH = 6;
    const FIELD_SCAN_MAX_GAP = 60;
    let fieldScanBuffer = '';
    let fieldScanLastKeyAt = 0;
    let fieldScanTarget = null;
    let fieldScanOriginalValue = '';

    function rows() {
        const grouped = new Map();
        document.querySelectorAll(FIELD_SELECTOR).forEach(function(field) {
            const row = Number(field.dataset.rowIndex);
            if (!Number.isFinite(row)) return;
            if (!grouped.has(row)) grouped.set(row, {});
            grouped.get(row)[field.dataset.posField] = field;
        });

        return Array.from(grouped.entries())
            .sort((a, b) => a[0] - b[0])
            .map(entry => ({
                index: entry[0],
                quantity: entry[1].quantity || null,
                price: entry[1].price || null,
                subtotal: entry[1].subtotal || null
            }));
    }

    function cartRows() {
        return Array.from(document.querySelectorAll('[data-pos-cart-line]'));
    }

    function focusCartRow(row) {
        if (!row || !document.contains(row)) return;
        row.focus({ preventScroll: true });
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function updateFocusedCartRow(row) {
        cartRows().forEach(function(cartRow) {
            cartRow.classList.toggle('pos-cart-row-focused', cartRow === row);
        });
    }

    document.addEventListener('focusin', function(event) {
        const row = event.target?.closest?.('[data-pos-cart-line]');
        if (row) updateFocusedCartRow(row);
    });

    document.addEventListener('click', function(event) {
        const row = event.target?.closest?.('[data-pos-cart-line]');
        if (!row || event.target?.closest?.('input, button, a, select, textarea, [data-pos-field]')) return;
        focusCartRow(row);
    }, true);

    function focusInput(input) {
        if (!input || !document.contains(input)) return;
        input.focus({ preventScroll: true });
        if (typeof input.select === 'function') input.select();

        const cart = input.closest('[data-pos-cart-scroll]');
        const row = input.closest('tr');
        if (!cart || !row) return;

        const cartBounds = cart.getBoundingClientRect();
        const rowBounds = row.getBoundingClientRect();
        const scrollMargin = 32;

        if (rowBounds.top < cartBounds.top) {
            cart.scrollBy({ top: rowBounds.top - cartBounds.top - scrollMargin, behavior: 'smooth' });
        } else if (rowBounds.bottom > cartBounds.bottom) {
            cart.scrollBy({ top: rowBounds.bottom - cartBounds.bottom + scrollMargin, behavior: 'smooth' });
        }
    }

    function focusBarcode() {
        if (document.querySelector('[data-pos-missing-barcode-dialog]')) return;
        const input = document.querySelector(BARCODE_SELECTOR);
        if (!input) return;
        input.focus({ preventScroll: true });
        if (typeof input.select === 'function') input.select();
    }

    function enqueueBarcode(barcode, mobileSearch = false) {
        const barcodeInput = document.querySelector(BARCODE_SELECTOR);
        const componentId = barcodeInput?.dataset.posComponentId;
        if (!barcodeInput || barcodeInput.disabled) return;
        if (!componentId || !barcode) return;

        barcodeQueue.push({ barcode, componentId, mobileSearch });
        processBarcodeQueue();
    }

    function resetExternalBarcodeCapture(restoreTarget = false) {
        if (restoreTarget && externalBarcodeCaptured && externalBarcodeTarget && document.contains(externalBarcodeTarget)) {
            externalBarcodeTarget.value = externalBarcodeOriginalValue ?? '';
            externalBarcodeTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }

        externalBarcodeBuffer = '';
        externalBarcodeLastKeyAt = 0;
        externalBarcodeCaptured = false;
        externalBarcodeTarget = null;
        externalBarcodeOriginalValue = null;
    }

    function resetFieldScan() {
        fieldScanBuffer = '';
        fieldScanLastKeyAt = 0;
        fieldScanTarget = null;
        fieldScanOriginalValue = '';
    }

    /**
     * When the cursor was left in the discount, paid or a cart field, a scanned barcode would be
     * typed into that amount. Recognise the scan by its speed, put the amount back and add the item.
     */
    function captureFieldScan(event, field) {
        const now = Date.now();

        if (event.key === 'Enter' || event.key === 'Tab') {
            const isScan = fieldScanTarget === field
                && fieldScanBuffer.length >= FIELD_SCAN_MIN_LENGTH
                && now - fieldScanLastKeyAt <= FIELD_SCAN_MAX_GAP;
            const barcode = fieldScanBuffer;
            const originalValue = fieldScanOriginalValue;
            resetFieldScan();
            if (!isScan) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            field.value = originalValue;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            enqueueBarcode(barcode);
            return;
        }

        if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) return;

        if (fieldScanTarget !== field || now - fieldScanLastKeyAt > FIELD_SCAN_MAX_GAP) {
            fieldScanTarget = field;
            fieldScanOriginalValue = String(field.value ?? '');
            fieldScanBuffer = event.key;
        } else {
            fieldScanBuffer += event.key;
        }
        fieldScanLastKeyAt = now;
    }

    async function processBarcodeQueue() {
        if (isProcessingBarcodeQueue || barcodeQueue.length === 0) return;

        isProcessingBarcodeQueue = true;

        try {
            while (barcodeQueue.length > 0) {
                const { barcode, componentId, mobileSearch } = barcodeQueue.shift();
                const barcodeInput = document.querySelector(BARCODE_SELECTOR);
                if (!barcodeInput || barcodeInput.disabled || barcodeInput.dataset.posComponentId !== componentId) continue;
                const component = window.Livewire?.find(componentId);

                if (!component || !barcode) continue;

                await component.$call(mobileSearch ? 'submitMobileProductSearch' : 'scanBarcode', barcode);
            }
        } catch (error) {
            console.error('تعذر تسجيل أحد الباركودات في نقطة البيع.', error);
        } finally {
            isProcessingBarcodeQueue = false;
            focusBarcode();
        }
    }

    document.addEventListener('keydown', function(event) {
        if (document.querySelector('[data-pos-missing-barcode-dialog]')) {
            resetExternalBarcodeCapture();
            return;
        }

        const barcodeInput = document.querySelector(BARCODE_SELECTOR);
        if (!barcodeInput || barcodeInput.disabled) {
            resetExternalBarcodeCapture();
            return;
        }
        const input = event.target?.closest?.(BARCODE_SELECTOR);
        if (input) {
            if (!['Enter', 'Tab'].includes(event.key) || (event.key === 'Tab' && !input.value.trim())) return;

            event.preventDefault();
            event.stopImmediatePropagation();

            const barcode = input.value.trim();
            const mobileSearch = input.dataset.posMobileSearch === 'true';
            if (mobileSearch) input.dispatchEvent(new Event('pos-unified-submitted'));
            input.value = '';
            enqueueBarcode(barcode, mobileSearch);
            return;
        }

        if (event.target?.closest?.('[data-pos-invoice-search]')) {
            resetExternalBarcodeCapture();
            return;
        }

        const numericField = event.target?.closest?.(NUMERIC_FIELD_SELECTOR);
        if (numericField) {
            resetExternalBarcodeCapture();
            captureFieldScan(event, numericField);
            return;
        }
        resetFieldScan();

        if (event.target?.closest?.('[data-pos-field], input:not([data-pos-barcode-input]), textarea, select, [contenteditable="true"]')) {
            resetExternalBarcodeCapture();
            return;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            if (externalBarcodeCaptured && externalBarcodeBuffer.length >= 4) {
                event.preventDefault();
                event.stopImmediatePropagation();
                const barcode = externalBarcodeBuffer;
                resetExternalBarcodeCapture(true);
                enqueueBarcode(barcode);
            } else {
                resetExternalBarcodeCapture();
            }
            return;
        }

        if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        const now = Date.now();
        if (!externalBarcodeLastKeyAt || now - externalBarcodeLastKeyAt > 90) {
            resetExternalBarcodeCapture();
            externalBarcodeBuffer = event.key;
            externalBarcodeTarget = event.target;
            externalBarcodeOriginalValue = typeof event.target?.value === 'string' ? event.target.value : null;
            externalBarcodeLastKeyAt = now;
            return;
        }

        externalBarcodeBuffer += event.key;
        externalBarcodeLastKeyAt = now;

        if (externalBarcodeBuffer.length >= 2) {
            externalBarcodeCaptured = true;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (externalBarcodeTarget && externalBarcodeOriginalValue !== null) {
                externalBarcodeTarget.value = externalBarcodeOriginalValue;
            }
        }
    }, true);

    document.addEventListener('livewire:navigating', function() {
        barcodeQueue.length = 0;
        resetExternalBarcodeCapture();
        resetFieldScan();
    });

    window.addEventListener('pos-scroll-cart-bottom', function() {
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                const cart = document.querySelector('[data-pos-cart-scroll]');
                if (cart) cart.scrollTop = cart.scrollHeight;
            });
        });
    });

    document.addEventListener('keydown', function(event) {
        if (document.querySelector('[data-pos-missing-barcode-dialog]')) {
            resetExternalBarcodeCapture();
            return;
        }

        const current = event.target?.closest?.(FIELD_SELECTOR);
        const key = event.key;
        const cartRow = event.target?.closest?.('[data-pos-cart-line]');

        // F7 removes the selected cart line and moves the selection to its neighbour.
        if (key === 'F7') {
            const selectedRow = cartRow || document.querySelector('[data-pos-cart-line].pos-cart-row-focused');
            const removeButton = selectedRow?.querySelector?.('[data-pos-remove-line]');
            if (!removeButton || removeButton.disabled) return;

            event.preventDefault();
            event.stopPropagation();
            if (event.repeat) return;

            const allCartRows = cartRows();
            const selectedIndex = allCartRows.indexOf(selectedRow);
            const neighbour = allCartRows[selectedIndex + 1] || allCartRows[selectedIndex - 1];
            removeButton.click();
            if (neighbour) {
                focusCartRow(neighbour);
            } else {
                focusBarcode();
            }

            return;
        }

        // Leave product search and result navigation to the product panel.
        if (event.target?.closest?.('[data-pos-products-panel], [data-pos-product-panel-search], [data-pos-product]')) {
            return;
        }

        if (cartRow && !current && ['ArrowUp', 'ArrowDown'].includes(key)) {
            const allCartRows = cartRows();
            const currentIndex = allCartRows.indexOf(cartRow);
            const targetIndex = key === 'ArrowUp' ? currentIndex - 1 : currentIndex + 1;
            const targetRow = allCartRows[targetIndex];

            if (targetRow) {
                event.preventDefault();
                event.stopPropagation();
                focusCartRow(targetRow);
            }

            return;
        }

        // الضغط على سهم الأعلى خارج السلة يأخذك مباشرة إلى كمية آخر منتج
        if (!current && key === 'ArrowUp') {
            const allRows = rows();
            if (!allRows.length) return;

            event.preventDefault();
            focusInput(
                allRows[allRows.length - 1].quantity ||
                allRows[allRows.length - 1].price ||
                allRows[allRows.length - 1].subtotal
            );
            return;
        }

        if (!current) return;

        // الضغط على Enter داخل حقول السلة للعودة إلى الباركود
        if (key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            focusBarcode();
            return;
        }

        if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(key)) {
            return;
        }

        const allRows = rows();
        if (!allRows.length) return;

        const rowIndex = Number(current.dataset.rowIndex);
        const type = current.dataset.posField;
        const pos = allRows.findIndex(r => r.index === rowIndex);

        if (pos < 0) return;

        let target = null;

        // التنقل بالأسهم داخل السلة (اتجاه RTL)
        if (key === 'ArrowLeft') {
            if (type === 'quantity') {
                target = allRows[pos].price;
            } else if (type === 'price') {
                target = allRows[pos].subtotal;
            } else if (type === 'subtotal') {
                const nextPos = pos >= allRows.length - 1 ? 0 : pos + 1;
                target = allRows[nextPos].quantity;
            }
        } else if (key === 'ArrowRight') {
            if (type === 'subtotal') {
                target = allRows[pos].price;
            } else if (type === 'price') {
                target = allRows[pos].quantity;
            } else if (type === 'quantity') {
                const previousPos = pos <= 0 ? allRows.length - 1 : pos - 1;
                target = allRows[previousPos].subtotal;
            }
        } else if (key === 'ArrowUp') {
            const targetPos = pos <= 0 ? allRows.length - 1 : pos - 1;
            target = allRows[targetPos][type];
        } else if (key === 'ArrowDown') {
            const targetPos = pos >= allRows.length - 1 ? 0 : pos + 1;
            target = allRows[targetPos][type];
        } else if (key === 'Home') {
            target = allRows[0][type];
        } else if (key === 'End') {
            target = allRows[allRows.length - 1][type];
        }

        if (target) {
            event.preventDefault();
            event.stopPropagation();
            focusInput(target);
        }
    }, true);
})();
