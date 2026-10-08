@php
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

    @if (isset($receipt['paid']))
        <div class="info-box">
            <span>المدفوع :</span>
            <strong>{{ $receipt['paid'] }}</strong>
        </div>
    @endif

    @if (isset($receipt['change']))
        <div class="info-box">
            <span>الباقي :</span>
            <strong>{{ $receipt['change'] }}</strong>
        </div>
    @endif

    @if (!empty($receipt['notes']))
        <div class="notes-box">
            <span class="notes-title">ملاحظات:</span>
            <span>{{ $receipt['notes'] }}</span>
        </div>
    @endif

    <div class="barcode-section">
        <p class="system-name">الشامل لايت للمحاسبة</p>
        <p class="print-time">
            تاريخ ووقت الطباعة {{ $receipt['date'] ?? '' }} {{ $receipt['time'] ?? '' }}
        </p>

        <div class="invoice-barcode-wrap">
            @if (!empty($barcodeBars))
                <div class="receipt-barcode" role="img" aria-label="باركود رقم الفاتورة {{ $invoiceBarcodeValue }}">
                    @foreach ($barcodeBars as $bar)
                        @if ($bar > 0)
                            <span class="barcode-bar" style="width: {{ $bar }}px"></span>
                        @else
                            <span class="barcode-space" style="width: {{ abs($bar) }}px"></span>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

<div class="no-print" style="display:none !important;">
    <button type="button" wire:click="printReceipt">طباعة الفاتورة</button>
</div>

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


    .receipt-promotion-label {
        margin-top: 2px;
        font-size: 9px;
        font-weight: bold;
        line-height: 1.25;
    }

    .receipt-final-total {
        font-weight: bold;
    }

    .receipt-original-total {
        margin-top: 1px;
        font-size: 9px;
        text-decoration: line-through;
        font-weight: bold;
    }

    .receipt-savings {
        margin-top: 1px;
        font-size: 9px;
        font-weight: bold;
    }

    .promotion-total-box {
        border: 2px solid #000;
        padding: 5px 8px;
        margin: 6px 0;
        text-align: center;
        font-weight: bold;
    }

    .promotion-total-title {
        font-size: 12px;
    }

    .promotion-total-value {
        margin-top: 2px;
        font-size: 17px;
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
        white-space: pre-wrap;
        word-break: break-word;
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
        padding-top: 5px;
        text-align: center;
    }

    .invoice-barcode-wrap {
        margin-top: 10px;
        padding-top: 8px;
        border-top: 1px dashed #000;
        text-align: center;
    }

    .receipt-barcode {
        display: flex;
        align-items: stretch;
        justify-content: center;
        width: 94%;
        height: 58px;
        margin: 0 auto;
        padding: 0 8px;
        box-sizing: border-box;
        overflow: hidden;
        background: #fff;
        line-height: 0;
        white-space: nowrap;
    }

    .barcode-bar,
    .barcode-space {
        display: block;
        flex: 0 0 auto;
        height: 58px;
    }

    .barcode-bar {
        background: #000;
    }

    .barcode-space {
        background: #fff;
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
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }
    }
</style>

<script>
(function () {
    function doPosPrint() {
        const receipt = document.getElementById('thermal-receipt');
        if (!receipt) {
            console.error('POS: #thermal-receipt not found');
            return;
        }

        // تأكد أن الفاتورة ظهرت قبل فتح نافذة الطباعة.
        receipt.style.display = 'block';

        setTimeout(function () {
            window.print();
        }, 100);
    }

    function registerPrintListener() {
        if (window.__posThermalPrintRegistered) {
            return;
        }

        if (!window.Livewire) {
            return;
        }

        window.__posThermalPrintRegistered = true;

        Livewire.on('print-receipt', function () {
            doPosPrint();
        });
    }

    // Livewire 3
    document.addEventListener('livewire:init', registerPrintListener);

    // إذا كان Livewire قد بدأ قبل تنفيذ هذا السكربت.
    if (window.Livewire) {
        registerPrintListener();
    }

    // دعم إعادة رسم الصفحة/المكوّن بواسطة Livewire.
    document.addEventListener('livewire:navigated', function () {
        setTimeout(registerPrintListener, 50);
    });

    // إرجاع الصفحة لطبيعتها بعد إغلاق نافذة الطباعة.
    window.addEventListener('afterprint', function () {
        const receipt = document.getElementById('thermal-receipt');
        if (receipt) {
            receipt.style.display = '';
        }
    });
})();
</script>
