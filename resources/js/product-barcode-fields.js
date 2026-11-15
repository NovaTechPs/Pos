/**
 * Barcode fields of the product form. A scanner sends barcodes faster than the server can add
 * an empty field, so whatever is scanned while a new field is on its way is held back and typed
 * into that field once it arrives, instead of piling up in the field of the previous barcode.
 */
window.productBarcodeFields = function (wire) {
    return {
        waitingForField: false,
        held: '',

        inputs() {
            return [...this.$root.querySelectorAll('[data-product-barcode-cell] input')];
        },

        focusBarcode(index, attempt = 0) {
            const input = this.inputs()[index];
            if (!input) {
                if (attempt < 100) {
                    setTimeout(() => this.focusBarcode(index, attempt + 1), 50);
                } else {
                    this.stopWaiting();
                }

                return;
            }

            input.scrollIntoView({ block: 'nearest' });
            input.focus();
            this.waitingForField = false;
            this.typeHeld(input);
        },

        stopWaiting() {
            this.waitingForField = false;
            this.held = '';
        },

        typeHeld(input) {
            if (this.held === '') {
                return;
            }

            const end = this.held.indexOf('\n');
            const barcode = end < 0 ? this.held : this.held.slice(0, end);
            this.held = end < 0 ? '' : this.held.slice(end + 1);
            input.value = String(input.value ?? '') + barcode;
            input.dispatchEvent(new Event('input', { bubbles: true }));

            if (end >= 0 && input.value.trim() !== '') {
                this.moveOn(input);
            }
        },

        moveOn(input) {
            const inputs = this.inputs();
            const current = inputs.indexOf(input);
            const empty = inputs.findIndex((other, index) => index !== current && String(other.value ?? '').trim() === '');
            if (empty >= 0) {
                this.focusBarcode(empty);

                return;
            }

            this.waitingForField = true;
            Promise.resolve(wire.addBarcodeField()).catch(() => this.stopWaiting());
        },

        onKey(event) {
            if (!this.inputs().includes(event.target)) {
                return;
            }

            if (this.waitingForField) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    this.held += '\n';
                } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                    event.preventDefault();
                    this.held += event.key;
                }

                return;
            }

            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            if (String(event.target.value ?? '').trim() !== '') {
                this.moveOn(event.target);
            }
        },
    };
};
