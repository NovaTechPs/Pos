@php
    $receiptTotal = (float) str_replace(',', '', (string) ($receipt['total_amount'] ?? 0));
    $receiptPaid = (float) str_replace(',', '', (string) ($receipt['paid'] ?? 0));
    $receiptChange = (float) str_replace(',', '', (string) ($receipt['change'] ?? 0));
    $receiptDue = max(0, $receiptTotal - $receiptPaid);
    $showPaid = isset($receipt['paid']) && abs($receiptPaid - $receiptTotal) > 0.001;
    $showChange = isset($receipt['change']) && $receiptChange > 0.001;

    // Generate CODE128-B on the server so the barcode is part of the HTML
    // sent to the printer. This does not depend on JavaScript or external libraries.
    $invoiceBarcodeValue = trim((string) ($receipt['invoice_no'] ?? ''));
    $code128Patterns = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112'
    ];

    $barcodeBars = [];

    if ($invoiceBarcodeValue !== '') {
        $codes = [104]; // CODE128-B start
        $valid = true;

        for ($i = 0, $len = strlen($invoiceBarcodeValue); $i < $len; $i++) {
            $code = ord($invoiceBarcodeValue[$i]) - 32;
            if ($code < 0 || $code > 95) {
                $valid = false;
                break;
            }
            $codes[] = $code;
        }

        if ($valid) {
            $checksum = 104;
            for ($i = 1, $count = count($codes); $i < $count; $i++) {
                $checksum += $codes[$i] * $i;
            }
            $codes[] = $checksum % 103;
            $codes[] = 106; // stop

            foreach ($codes as $code) {
                $pattern = $code128Patterns[$code] ?? '';
                $black = true;
                for ($j = 0, $length = strlen($pattern); $j < $length; $j++) {
                    $moduleWidth = (int) $pattern[$j];
                    if ($black) {
                        $barcodeBars[] = $moduleWidth;
                    } else {
                        $barcodeBars[] = -$moduleWidth;
                    }
                    $black = !$black;
                }
            }
        }
    }
@endphp

<div id="thermal-receipt" class="receipt-box" dir="rtl">
    <div class="header">
        <h2 class="store-title">{{ $receipt['store_name'] ?? 'نقطة البيع' }}</h2>
        @if (!empty($receipt['store_address']))
            <p class="notice">{{ $receipt['store_address'] }}</p>
        @endif
        @if (!empty($receipt['store_phone']))
            <p class="notice" dir="ltr">{{ $receipt['store_phone'] }}</p>
        @endif
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
                    <td class="item-name">
                        {{ $item['name'] }}

                        @if (!empty($item['has_promotion']))
                            <div class="receipt-promotion-label">
                                {{ $item['offer_label'] }}
                            </div>
                        @endif
                    </td>
                    <td>{{ $item['qty'] }}</td>
                    <td>{{ $item['price'] }}</td>
                    <td>
                        <div class="receipt-final-total">
                            {{ $item['total'] }}
                        </div>

                        @if (!empty($item['has_promotion']))
                            <div class="receipt-original-total">
                                {{ $item['normal_total'] }}
                            </div>
                            <div class="receipt-savings">
                                وفّرت {{ $item['promotion_savings'] }}
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (isset($receipt['subtotal']))
        <div class="info-box">
            <span>المجموع :</span>
            <strong>{{ $receipt['subtotal'] }}</strong>
        </div>
    @endif

    @if (isset($receipt['discount']) && (float) str_replace(',', '', $receipt['discount']) > 0)
        <div class="info-box">
            <span>الخصم :</span>
            <strong>{{ $receipt['discount'] }}</strong>
        </div>
    @endif
    @if (isset($receipt['total_promotion_savings']) && (float) str_replace(',', '', $receipt['total_promotion_savings']) > 0)
        <div class="promotion-total-box">
            <div class="promotion-total-title">✓ وفّرت من العروض</div>
            <div class="promotion-total-value">{{ $receipt['total_promotion_savings'] }}</div>
        </div>
    @endif


    <div class="net-box">
        <span>الصافي للدفع ({{ $receipt['currency'] ?? 'ش.ض' }}) :</span>
        <strong class="net-value">{{ $receipt['total_amount'] ?? 0 }}</strong>
    </div>

    @if ($showPaid)
        <div class="info-box">
            <span>المدفوع :</span>
            <strong>{{ $receipt['paid'] }}</strong>
        </div>
    @endif

    @if ($receiptDue > 0.001)
        <div class="info-box">
            <span>المتبقي :</span>
            <strong>{{ number_format($receiptDue, 2) }}</strong>
        </div>
    @endif

    @if ($showChange)
        <div class="info-box">
            <span>الباقي :</span>
            <strong>{{ $receipt['change'] }}</strong>
        </div>
    @endif

    @if (!empty($receipt['notes']))
        <div class="notes-box">
            <span class="notes-title">ملاحظات:</span>
            <span class="notes-content">{{ $receipt['notes'] }}</span>
        </div>
    @endif

    <div class="barcode-section">
        <p class="print-time">
            تاريخ ووقت الطباعة {{ $receipt['date'] ?? '' }} {{ $receipt['time'] ?? '' }}
        </p>

        <div class="invoice-barcode-wrap">
            @if (!empty($barcodeBars))
                @php $barcodeOffset = 10; @endphp
                <svg class="receipt-barcode" xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 {{ array_sum(array_map('abs', $barcodeBars)) + 20 }} 40"
                    preserveAspectRatio="xMidYMid meet" role="img"
                    aria-label="باركود رقم الفاتورة {{ $invoiceBarcodeValue }}">
                    @foreach ($barcodeBars as $bar)
                        @if ($bar > 0)
                            <rect x="{{ $barcodeOffset }}" y="0" width="{{ $bar }}" height="40" fill="#000" />
                        @endif
                        @php $barcodeOffset += abs($bar); @endphp
                    @endforeach
                </svg>
            @endif
        </div>
    </div>
    <x-invoice-copyright />
</div>

<div class="no-print" style="display:none !important;">
    <button type="button" wire:click="printReceipt">طباعة الفاتورة</button>
</div>

<style>
    .receipt-box {
        display: none;
        width: 72mm;
        max-width: 100%;
        background: #fff;
        padding: 3px;
        box-sizing: border-box;
        font-family: Tahoma, Arial, sans-serif;
        font-size: 11px;
        font-weight: 400;
        color: #000;
        direction: rtl;
        text-align: center;
        margin: 0 auto;
    }

    .header .store-title {
        font-size: 16px;
        font-weight: 600;
        margin: 0 0 2px;
    }

    .header .notice {
        font-size: 11px;
        margin: 1px 0;
        font-weight: 400;
    }

    .header .copy-type {
        font-size: 11px;
        font-weight: 400;
        margin: 2px 0 6px;
    }

    .meta-info {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        overflow-wrap: anywhere;
        gap: 4px;
        font-size: 10px;
        font-weight: 400;
        margin-bottom: 4px;
        padding: 0 2px;
    }

    .cashier {
        font-size: 10px;
        font-weight: 400;
        margin-bottom: 5px;
    }

    .items-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin-bottom: 4px;
    }

    .items-table th,
    .items-table td {
        border: 1px solid #000;
        padding: 3px 2px;
        text-align: center;
        font-size: 9.5px;
        overflow-wrap: anywhere;
        vertical-align: middle;
    }

    .items-table th {
        font-weight: 600;
    }

    .items-table td {
        font-weight: 400;
    }

    .items-table tr {
        break-inside: avoid;
    }

    .items-table .item-name {
        text-align: right;
        font-size: 10px;
        line-height: 1.2;
        word-break: break-word;
    }


    .receipt-promotion-label {
        margin-top: 2px;
        font-size: 9px;
        font-weight: 400;
        line-height: 1.25;
    }

    .receipt-final-total {
        font-weight: 500;
    }

    .receipt-original-total {
        margin-top: 1px;
        font-size: 9px;
        text-decoration: line-through;
        font-weight: 400;
    }

    .receipt-savings {
        margin-top: 1px;
        font-size: 9px;
        font-weight: 400;
    }

    .promotion-total-box {
        border: 1px solid #000;
        padding: 4px 6px;
        margin: 4px 0;
        text-align: center;
        font-weight: 400;
    }

    .promotion-total-title {
        font-size: 10px;
    }

    .promotion-total-value {
        margin-top: 2px;
        font-size: 13px;
    }

    .info-box {
        border: 1px solid #000;
        padding: 4px 6px;
        margin-bottom: 3px;
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        overflow-wrap: anywhere;
        gap: 4px 10px;
        font-size: 11px;
        font-weight: 400;
    }

    .notes-box {
        border: 1px dashed #000;
        padding: 5px 6px;
        margin: 5px 0;
        text-align: right;
        font-size: 11px;
        line-height: 1.45;
        font-weight: 400;
        white-space: normal;
        word-break: break-word;
    }

    .notes-title {
        display: block;
        margin-bottom: 3px;
    }

    .notes-content {
        display: block;
        white-space: pre-wrap;
    }

    .net-box {
        border: 2px solid #000;
        padding: 5px 6px;
        margin: 4px 0;
        display: flex;
        justify-content: center;
        align-items: center;
        flex-wrap: wrap;
        overflow-wrap: anywhere;
        gap: 4px 8px;
        font-size: 13px;
        font-weight: 600;
    }

    .net-value {
        font-size: 13px;
        font-weight: 600;
    }

    .receipt-box strong {
        font-weight: 600;
    }

    .barcode-section {
        margin-top: 5px;
        padding-top: 3px;
        text-align: center;
    }

    .invoice-barcode-wrap {
        margin-top: 6px;
        padding-top: 5px;
        border-top: 1px dashed #000;
        text-align: center;
    }

    .receipt-barcode {
        display: block;
        width: 94%;
        height: 40px;
        margin: 0 auto;
        padding: 0;
        box-sizing: border-box;
        overflow: visible;
        background: #fff;
        line-height: 0;
        white-space: nowrap;
    }

    .system-name {
        font-size: 10px;
        margin: 2px 0 0;
    }

    .print-time {
        font-size: 9px;
        margin: 1px 0;
    }

    @media print {
        html,
        body.printing-pos-receipt {
            display: block !important;
            height: auto !important;
            min-height: 0 !important;
            overflow: visible !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        body.printing-pos-receipt > :not(#thermal-receipt) {
            display: none !important;
        }

        #thermal-receipt {
            display: block !important;
            position: static !important;
            width: 72mm !important;
            max-width: 100% !important;
            height: auto !important;
            overflow: visible !important;
            padding: 1mm !important;
            margin: 0 auto !important;
        }

        @page {
            size: auto;
            margin: 2mm;
        }
    }
</style>

<script>
(function () {
    if (window.__posThermalPrintInitialized) return;
    window.__posThermalPrintInitialized = true;

    let receiptPlaceholder = null;
    let originalDisplay = '';
    let printScheduled = false;

    function prepareReceipt() {
        const receipt = document.getElementById('thermal-receipt');
        if (!receipt) return false;

        if (!receiptPlaceholder) {
            originalDisplay = receipt.style.display;
            receiptPlaceholder = document.createComment('thermal-receipt-position');
            receipt.before(receiptPlaceholder);
            document.body.appendChild(receipt);
        }

        document.body.classList.add('printing-pos-receipt');
        receipt.style.display = 'block';
        return true;
    }

    function restoreReceipt() {
        const receipt = document.getElementById('thermal-receipt');
        if (receiptPlaceholder && receiptPlaceholder.isConnected && receipt) {
            receiptPlaceholder.replaceWith(receipt);
            receipt.style.display = originalDisplay;
        }
        receiptPlaceholder = null;
        printScheduled = false;
        document.body.classList.remove('printing-pos-receipt');
    }

    function doPosPrint() {
        if (printScheduled || !prepareReceipt()) return;
        printScheduled = true;
        setTimeout(function () {
            if (document.body.classList.contains('printing-pos-receipt')) window.print();
        }, 100);
    }

    function registerPrintListener() {
        if (window.__posThermalPrintRegistered || !window.Livewire) return;
        window.__posThermalPrintRegistered = true;
        Livewire.on('print-receipt', doPosPrint);
    }

    document.addEventListener('livewire:init', registerPrintListener);
    if (window.Livewire) registerPrintListener();
    document.addEventListener('livewire:navigated', registerPrintListener);
    document.addEventListener('livewire:navigating', restoreReceipt);
    window.addEventListener('beforeprint', prepareReceipt);
    window.addEventListener('afterprint', restoreReceipt);
})();
</script>
