<div id="thermal-receipt" class="receipt-box" dir="rtl">
    <div class="header">
        <h2 class="store-title">{{ $receipt['store_name'] ?? 'نقطة البيع' }}</h2>
        <p class="notice">{{ $receipt['notice'] ?? 'شكراً لتعاملكم معنا' }}</p>
        <p class="copy-type">{{ $receipt['copy_type'] ?? 'فاتورة بيع' }}</p>
    </div>

    <div class="meta-info">
        <span>فاتورة: {{ $receipt['invoice_no'] ?? '-' }}</span>
        <span>{{ $receipt['date'] ?? '' }}</span>
        <span>{{ $receipt['time'] ?? '' }}</span>
    </div>

    @if (!empty($receipt['cashier']))
        <div class="cashier">الكاشير: {{ $receipt['cashier'] }}</div>
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

    @if (
        isset($receipt['discount']) &&
        (float) str_replace(',', '', $receipt['discount']) > 0
    )
        <div class="info-box">
            <span>الخصم :</span>
            <strong>{{ $receipt['discount'] }}</strong>
        </div>
    @endif

    <div class="net-box">
        <span>
            الصافي للدفع ({{ $receipt['currency'] ?? 'ش.ض' }}) :
        </span>

        <strong class="net-value">
            {{ $receipt['total_amount'] ?? 0 }}
        </strong>
    </div>

    @if (!empty($receipt['notes']))
        <div class="notes-box">
            <span class="notes-title">ملاحظات:</span>
            <span>{{ $receipt['notes'] }}</span>
        </div>
    @endif

    <div class="barcode-section">
        <svg id="receipt-barcode"></svg>

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


<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>


<style>
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

    .barcode-section {
        margin-top: 8px;
        text-align: center;
    }

    #receipt-barcode {
        width: 80%;
        height: 45px;
    }

    .system-name {
        font-size: 12px;
        margin: 3px 0 0;
    }

    .print-time {
        font-size: 10px;
        margin: 2px 0;
    }


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

            /*
             * تحريك الفاتورة 2mm إلى اليسار
             * حتى لا يتم أكل الطرف الأيمن أثناء الطباعة.
             */
            transform: translateX(-2mm) !important;
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }
    }
</style>


<script>

    function generatePosReceiptBarcode() {

        const barcode =
            document.getElementById('receipt-barcode');

        const invoiceNo =
            @js($receipt['invoice_no'] ?? '');

        if (
            !barcode ||
            !invoiceNo ||
            typeof JsBarcode === 'undefined'
        ) {
            return;
        }

        JsBarcode(
            barcode,
            String(invoiceNo),
            {
                format: 'CODE128',
                displayValue: false,
                height: 40,
                margin: 0,
                width: 1.5
            }
        );
    }


    function printPosReceipt() {

        generatePosReceiptBarcode();

        window.print();
    }


    document.addEventListener('livewire:init', () => {

        generatePosReceiptBarcode();

        Livewire.on('print-receipt', () => {

            setTimeout(() => {

                generatePosReceiptBarcode();

                printPosReceipt();

            }, 80);

        });

    });

</script>
