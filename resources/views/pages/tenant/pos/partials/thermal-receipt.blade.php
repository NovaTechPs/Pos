<div id="thermal-receipt" class="receipt-box" dir="rtl">

    <div class="header">
        {{-- <h2 class="store-title">
            {{ $receipt['store_name'] ?? 'نقطة البيع' }}
        </h2> --}}

        <p class="notice">
            {{ $receipt['notice'] ?? 'شكراً لتعاملكم معنا' }}
        </p>

        <p class="copy-type">
            {{ $receipt['copy_type'] ?? 'فاتورة بيع' }}
        </p>
    </div>

    <div class="meta-info">
        <span>
            فاتورة: {{ $receipt['invoice_no'] ?? '-' }}
        </span>

        <span>
            {{ $receipt['date'] ?? '' }}
        </span>

        <span>
            {{ $receipt['time'] ?? '' }}
        </span>
    </div>

    @if (!empty($receipt['cashier']))
        <div class="cashier">
            الكاشير: {{ $receipt['cashier'] }}
        </div>
    @endif

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:8%">#</th>
                <th style="width:46%">البيان</th>
                <th style="width:14%">كمية</th>
                <th style="width:16%">سعر</th>
                <th style="width:16%">مبلغ</th>
            </tr>
        </thead>

        <tbody>
            @foreach (($receipt['items'] ?? []) as $item)
                <tr>
                    <td>{{ $item['id'] }}</td>
                    <td class="item-name">{{ $item['name'] }}</td>
                    <td>{{ $item['qty'] }}</td>
                    <td>{{ $item['price'] }}</td>
                    <td>{{ $item['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="info-box">
        <span>مجموع الكميات :</span>
        <strong>{{ $receipt['total_qty'] ?? 0 }}</strong>
    </div>

    @if (isset($receipt['subtotal']))
        <div class="info-box">
            <span>المجموع :</span>
            <strong>{{ $receipt['subtotal'] }}</strong>
        </div>
    @endif

    @if (isset($receipt['discount']) && (float) str_replace(',', '',$receipt['discount']) > 0)
        <div class="info-box">
            <span>الخصم :</span>
            <strong>{{ $receipt['discount'] }}</strong>
        </div>
    @endif

    <div class="net-box">
        <span>الصافي للدفع ({{ $receipt['currency'] ?? 'ش.ض' }}) :</span>
        <strong class="net-value">{{ $receipt['total_amount'] ?? 0 }}</strong>
    </div>

    @if (!empty($receipt['notes']))
        <div class="notes-box">
            <span class="notes-title">ملاحظات:</span>
            <span>{{ $receipt['notes'] }}</span>
        </div>
    @endif

    {{-- =====================================================
         BARCODE
         ===================================================== --}}
    <div class="barcode-section">
        <svg id="receipt-barcode" data-invoice-no="{{ $receipt['invoice_no'] ?? '' }}"></svg>

        <p class="print-time">
            تاريخ ووقت الطباعة
            {{ $receipt['date'] ?? '' }}
            {{ $receipt['time'] ?? '' }}
        </p>
    </div>

</div>

<div class="no-print" style="display:none !important;">
    <button type="button" wire:click="printReceipt">
        طباعة الفاتورة
    </button>
</div>

<style>
    /* =====================================================
       RECEIPT STYLES
       ===================================================== */
    .receipt-box {
        display: none;
        width: 80mm;
        background: #fff;
        padding: 5px;
        box-sizing: border-box;
        font-family: Tahoma, Arial, sans-serif;
        font-size: 15px;
        color: #000;
        direction: rtl;
        text-align: center;
        margin: 0 auto;
    }

    /* HEADER */
    .header .store-title {
        font-size: 21px;
        font-weight: bold;
        margin: 0 0 4px;
    }

    .header .notice {
        font-size: 13px;
        margin: 2px 0;
        font-weight: bold;
    }

    .header .copy-type {
        font-size: 14px;
        font-weight: bold;
        margin: 3px 0 10px;
    }

    /* META & CASHIER */
    .meta-info {
        display: flex;
        justify-content: space-between;
        gap: 4px;
        font-size: 12px;
        font-weight: bold;
        margin-bottom: 6px;
        padding: 0 2px;
    }

    .cashier {
        font-size: 12px;
        font-weight: bold;
        margin-bottom: 7px;
    }

    /* ITEMS TABLE */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
    }

    .items-table th,
    .items-table td {
        border: 1px solid #000;
        padding: 6px 3px;
        text-align: center;
        font-size: 12px;
        font-weight: bold;
        vertical-align: middle;
    }

    .items-table .item-name {
        text-align: right;
        font-size: 12px;
        line-height: 1.35;
        word-break: break-word;
    }

    /* INFO BOXES & TOTALS */
    .info-box {
        border: 1px solid #000;
        padding: 6px 8px;
        margin-bottom: 5px;
        display: flex;
        justify-content: center;
        gap: 15px;
        font-size: 14px;
        font-weight: bold;
    }

    .notes-box {
        border: 1px dashed #000;
        padding: 7px 8px;
        margin: 7px 0;
        text-align: right;
        font-size: 13px;
        line-height: 1.45;
        font-weight: bold;
    }

    .notes-title {
        display: block;
        margin-bottom: 3px;
    }

    .net-box {
        border: 2px solid #000;
        padding: 6px 8px;
        margin: 6px 0;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 15px;
        font-size: 18px;
        font-weight: bold;
    }

    .net-value {
        font-size: 16px;
    }

    /* BARCODE SECTION */
    .barcode-section {
        width: 100%;
        margin-top: 12px;
        text-align: center;
        display: block;
    }

    #receipt-barcode {
        display: block !important;
        width: 60mm !important;
        height: 15mm !important;
        margin: 0 auto !important;
    }

    .print-time {
        font-size: 10px;
        margin: 3px 0 0;
        font-weight: normal;
    }

    /* PRINT MEDIA RULES */
    @media print {
        body * {
            visibility: hidden !important;
        }

        #thermal-receipt,
        #thermal-receipt * {
            visibility: visible !important;
        }

        #thermal-receipt {
            display: block !important;
            position: absolute !important;
            right: 0 !important;
            left: auto !important;
            top: 0 !important;
            width: 80mm !important;
            padding: 0 !important;
            margin: 0 !important;
            transform: translateX(-2mm) !important;
        }

        #receipt-barcode {
            display: block !important;
            width: 60mm !important;
            height: 15mm !important;
            margin: 0 auto !important;
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }
    }
</style>

<script>
    const POS_CODE128_B = [
        "212222", "222122", "222221", "121223", "121322", "131222", "122213", "122312", "132212", "221213",
        "221312", "231212", "112232", "122132", "122231", "113222", "123122", "123221", "223211", "221132",
        "221231", "213212", "223112", "312131", "311222", "321122", "321221", "312212", "322112", "322211",
        "212123", "212321", "232121", "111323", "131123", "131321", "112313", "132113", "132311", "211313",
        "231113", "231311", "112133", "112331", "132131", "113123", "113321", "133121", "313121", "211331",
        "231131", "213113", "213311", "213131", "311123", "311321", "331121", "312113", "312311", "332111",
        "314111", "221411", "431111", "111224", "111422", "121124", "121421", "141122", "141221", "112214",
        "112412", "122114", "122411", "142112", "142211", "241211", "221114", "413111", "241112", "134111",
        "111242", "121142", "121241", "114212", "124112", "124211", "411212", "421112", "421211", "212141",
        "214121", "412121", "111143", "111341", "131141", "114113", "114311", "411113", "411311", "113141",
        "114131", "311141", "411131", "211412", "211214", "211232", "2331112"
    ];

    function createPosCode128(value) {
        const svg = document.getElementById('receipt-barcode');
        if (!svg) return;

        value = String(value || '').trim();
        if (!value) {
            svg.innerHTML = '';
            return;
        }

        const encodedValues = [104];
        for (let i = 0; i < value.length; i++) {
            const ascii = value.charCodeAt(i);
            if (ascii >= 32 && ascii <= 126) {
                encodedValues.push(ascii - 32);
            }
        }

        let checksum = encodedValues[0];
        for (let i = 1; i < encodedValues.length; i++) {
            checksum += encodedValues[i] * i;
        }
        encodedValues.push(checksum % 103);
        encodedValues.push(106);

        const patterns = [];
        let totalModules = 0;

        encodedValues.forEach(code => {
            const pattern = POS_CODE128_B[code];
            if (pattern) {
                patterns.push(pattern);
                for (let i = 0; i < pattern.length; i++) {
                    totalModules += Number(pattern[i]);
                }
            }
        });

        const moduleWidth = 2;
        const barcodeHeight = 50;
        const quietZone = 10;
        const totalWidth = (totalModules * moduleWidth) + (quietZone * 2);

        svg.innerHTML = '';
        svg.setAttribute('viewBox', `0 0 ${totalWidth} ${barcodeHeight}`);

        let x = quietZone;
        let drawBar = true;

        patterns.forEach(pattern => {
            for (let i = 0; i < pattern.length; i++) {
                const moduleSize = Number(pattern[i]) * moduleWidth;
                if (drawBar) {
                    const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                    rect.setAttribute('x', x);
                    rect.setAttribute('y', 0);
                    rect.setAttribute('width', moduleSize);
                    rect.setAttribute('height', barcodeHeight);
                    rect.setAttribute('fill', '#000');
                    svg.appendChild(rect);
                }
                x += moduleSize;
                drawBar = !drawBar;
            }
        });
    }

    function generatePosReceiptBarcode() {
        const svg = document.getElementById('receipt-barcode');
        if (!svg) return;

        // جلب رقم الفاتورة من data attribute أو متغير Blade مباشرة
        const invoiceNo = svg.getAttribute('data-invoice-no') || @js($receipt['invoice_no'] ?? '');

        if (invoiceNo) {
            createPosCode128(invoiceNo);
        }
    }

    function printPosReceipt() {
        generatePosReceiptBarcode();
        setTimeout(() => {
            window.print();
        }, 200);
    }

    document.addEventListener('DOMContentLoaded', generatePosReceiptBarcode);

    document.addEventListener('livewire:init', () => {
        generatePosReceiptBarcode();

        Livewire.on('print-receipt', () => {
            generatePosReceiptBarcode();
            setTimeout(() => {
                window.print();
            }, 200);
        });
    });

    document.addEventListener('livewire:navigated', generatePosReceiptBarcode);
</script>
