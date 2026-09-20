<?php

use Livewire\Component;

new class extends Component {
    public $receipt = [
        'store_name' => 'فانوس',
        'notice' => 'راجع فاتورتك وتأكد من مشترياتك قبل مغادرة المعرض',
        'copy_type' => 'النسخة الأصلية',
        'invoice_no' => '100035970',
        'date' => '14/09/2026',
        'time' => '5:33 م',
        'items' => [
            [
                'id' => 1,
                'name' => 'مج زجاج مع غطاء خشب + جه/s17 مصاصه',
                'qty' => 1,
                'price' => 10,
                'total' => 10
            ]
        ],
        'total_qty' => 1,
        'total_amount' => 10,
        'net_amount' => 10,
        'currency' => 'ش.ض',
        'system_name' => 'الشامل لايت للمحاسبة'
    ];

    public function printReceipt()
    {
        $this->dispatch('trigger-print');
    }
};
?>

<div>
    <!-- زر الطباعة -->
    <div class="no-print mb-4">
        <button wire:click="printReceipt" class="px-6 py-2 bg-blue-600 text-white font-bold rounded shadow hover:bg-blue-700">
            طباعة الفاتورة
        </button>
    </div>

    <!-- قالب الفاتورة المخصص للطباعة الحرارية -->
    <div id="receipt-print-area" class="receipt-box">
        <!-- الرأسية / Header -->
        <div class="header">
            <h2 class="store-title">{{ $receipt['store_name'] }}</h2>
            <p class="notice">{{ $receipt['notice'] }}</p>
            <p class="copy-type">{{ $receipt['copy_type'] }}</p>
        </div>

        <!-- تفاصيل التاريخ ورقم الفاتورة -->
        <div class="meta-info">
            <span class="inv-no">{{ $receipt['invoice_no'] }}</span>
            <span class="inv-date">{{ $receipt['date'] }}</span>
            <span class="inv-time">{{ $receipt['time'] }}</span>
        </div>

        <!-- جدول المنتجات -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 8%;">#</th>
                    <th style="width: 46%;">البيان</th>
                    <th style="width: 14%;">كمية</th>
                    <th style="width: 16%;">سعر</th>
                    <th style="width: 16%;">مبلغ</th>
                </tr>
            </thead>
            <tbody>
                @foreach($receipt['items'] as $item)
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

        <!-- مجموع الكميات -->
        <div class="info-box">
            <span>مجموع الكميات :</span>
            <strong>{{ $receipt['total_qty'] }}</strong>
        </div>

        <!-- المجموع -->
        <div class="info-box">
            <span>المجموع :</span>
            <strong>{{ $receipt['total_amount'] }}</strong>
        </div>

        <!-- الصافي للدفع (إطار بارز) -->
        <div class="net-box">
            <span>الصافي للدفع ({{ $receipt['currency'] }}) :</span>
            <strong class="net-value">{{ $receipt['net_amount'] }}</strong>
        </div>

        <!-- الباركود والتذييل -->
        <div class="barcode-section">
            <!-- توليد باركود بسيط باستخدام SVG أو مكتبة JsBarcode -->
            <svg id="barcode"></svg>
            <p class="system-name">{{ $receipt['system_name'] }}</p>
            <p class="print-time">تاريخ ووقت الطباعة {{ $receipt['date'] }} {{ $receipt['time'] }}</p>
        </div>
    </div>

    <!-- مكتبة JsBarcode لتوليد الباركود تلقائياً -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

    <!-- تنسيقات CSS الخاصة بتطابق شكل الفاتورة -->
    <style>
        .receipt-box {
            width: 80mm;
            background: #fff;
            padding: 5px;
            font-family: 'Courier New', Courier, monospace, 'Arial', sans-serif;
            font-size: 13px;
            color: #000;
            direction: rtl;
            text-align: center;
            margin: 0 auto;
        }

        .header .store-title {
            font-size: 18px;
            font-weight: bold;
            margin: 0 0 4px 0;
        }

        .header .notice {
            font-size: 11px;
            margin: 2px 0;
            font-weight: bold;
        }

        .header .copy-type {
            font-size: 12px;
            font-weight: bold;
            margin: 2px 0 8px 0;
        }

        .meta-info {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 4px;
            padding: 0 2px;
        }

        /* تنسيق الجدول والحدود */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        .items-table th, .items-table td {
            border: 1px solid #000;
            padding: 4px 2px;
            text-align: center;
            font-size: 11px;
            font-weight: bold;
        }

        .items-table .item-name {
            text-align: right;
            font-size: 11px;
            line-height: 1.2;
        }

        /* مربعات المجموع ومجموع الكميات */
        .info-box {
            border: 1px solid #000;
            padding: 4px 8px;
            margin-bottom: 4px;
            display: flex;
            justify-content: center;
            gap: 15px;
            font-size: 13px;
            font-weight: bold;
        }

        /* مربع الصافي للدفع */
        .net-box {
            border: 2px solid #000;
            padding: 6px 8px;
            margin: 6px 0;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            font-size: 15px;
            font-weight: bold;
        }

        .net-value {
            font-size: 16px;
        }

        /* الباركود والتذييل */
        .barcode-section {
            margin-top: 8px;
            text-align: center;
        }

        #barcode {
            width: 80%;
            height: 45px;
        }

        .system-name {
            font-size: 10px;
            margin: 2px 0 0 0;
        }

        .print-time {
            font-size: 9px;
            margin: 1px 0;
        }

        /* إعدادات الطباعة المباشرة */
        @media print {
            .no-print {
                display: none !important;
            }
            body * {
                visibility: hidden;
            }
            #receipt-print-area, #receipt-print-area * {
                visibility: visible;
            }
            #receipt-print-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 80mm;
                padding: 0;
                margin: 0;
            }
            @page {
                size: 80mm auto;
                margin: 0;
            }
        }
    </style>

    <script>
        function generateBarcode() {
            if (document.getElementById("barcode")) {
                JsBarcode("#barcode", "{{ $receipt['invoice_no'] }}", {
                    format: "CODE128",
                    displayValue: false,
                    height: 40,
                    margin: 0
                });
            }
        }

        document.addEventListener('livewire:initialized', () => {
            generateBarcode();

            Livewire.on('trigger-print', () => {
                generateBarcode();
                window.print();
            });
        });
    </script>
</div>
