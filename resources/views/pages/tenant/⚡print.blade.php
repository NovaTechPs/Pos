<?php

use Livewire\Component;

new class extends Component
{
    // بيانات الفاتورة التجريبية (يمكنك ربطها ببيانات السلة لديك)
    public array $order = [
        'id' => '100035970',
        'date' => '14/09/2026',
        'time' => '5:33 م',
        'store_name' => 'فانوس',
        'items' => [
            [
                'name' => 'مج زجاج مع غطاء خشب + مصاصة s17/دج',
                'qty' => 1,
                'price' => 10,
                'total' => 10,
            ]
        ],
        'total_qty' => 1,
        'subtotal' => 10,
        'grand_total' => 10,
    ];

    public function printInvoice()
    {
        // إطلاق حدث الطباعة للـ Front-end
        $this->dispatch('trigger-print');
    }
};
?>

<div class="p-6" x-data x-on:trigger-print.window="setTimeout(() => window.print(), 200)">

    {{-- زر الطباعة داخل الواجهة الرئيسية --}}
    <button wire:click="printInvoice" class="px-4 py-2 bg-blue-600 text-white rounded-lg shadow hover:bg-blue-700 font-bold">
        طباعة الفاتورة 🖨️
    </button>

    {{-- قالب الفاتورة الحرارية (مخفي في الشاشة ويظهر فقط عند الطباعة) --}}
    <div id="thermal-receipt" class="hidden print:block">
        <style>
            @media print {
                body * {
                    visibility: hidden;
                }
                #thermal-receipt, #thermal-receipt * {
                    visibility: visible;
                }
                #thermal-receipt {
                    position: absolute;
                    left: 0;
                    top: 0;
                    width: 58mm; /* قياس الورق الحراري للـ POS */
                    margin: 0;
                    padding: 2mm 0;
                    font-family: Arial, sans-serif;
                    font-size: 11px;
                    color: #000;
                    direction: rtl;
                    text-align: center;
                }
                @page {
                    size: 58mm auto;
                    margin: 0;
                }
            }

            .receipt-header {
                margin-bottom: 5px;
            }
            .store-title {
                font-size: 16px;
                font-weight: bold;
            }
            .sub-title {
                font-size: 10px;
                margin: 2px 0;
            }
            .meta-info {
                display: flex;
                justify-content: space-between;
                font-size: 10px;
                margin: 6px 0 4px 0;
                padding: 0 2px;
            }

            /* جدول الحدود السوداء العريضة المطابق للصورة */
            table.receipt-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 4px;
            }
            table.receipt-table th,
            table.receipt-table td {
                border: 1px solid #000;
                padding: 3px 2px;
                font-size: 10px;
                text-align: center;
            }

            /* صناديق المجاميع */
            .summary-box {
                border: 1px solid #000;
                padding: 4px;
                margin-top: 4px;
                font-size: 11px;
                font-weight: bold;
                text-align: right;
            }
            .total-box {
                border: 2px solid #000;
                padding: 6px;
                margin-top: 5px;
                font-size: 12px;
                font-weight: bold;
                text-align: center;
            }
            .barcode-container {
                margin-top: 8px;
            }
        </style>

        {{-- العناوين الرئيسية --}}
        <div class="receipt-header">
            <div class="store-title">{{ $order['store_name'] }}</div>
            <div class="sub-title">راجع فاتورتك وتأكد من مشترياتك قبل مغادرة المعرض</div>
            <div class="sub-title" style="font-weight: bold;">النسخة الأصلية</div>
        </div>

        {{-- رقم الفاتورة والتاريخ --}}
        <div class="meta-info">
            <span>{{ $order['id'] }}</span>
            <span>{{ $order['date'] }}</span>
            <span>{{ $order['time'] }}</span>
        </div>

        {{-- جدول الأصناف --}}
        <table class="receipt-table">
            <thead>
                <tr>
                    <th style="width: 8%;">#</th>
                    <th style="width: 50%;">البيان</th>
                    <th style="width: 12%;">كمية</th>
                    <th style="width: 15%;">سعر</th>
                    <th style="width: 15%;">مبلغ</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order['items'] as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td style="text-align: right;">{{ $item['name'] }}</td>
                        <td>{{ $item['qty'] }}</td>
                        <td>{{ $item['price'] }}</td>
                        <td>{{ $item['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- إجمالي الكميات والمجموع --}}
        <div class="summary-box">
            مجموع الكميات : {{ $order['total_qty'] }}
        </div>

        <div class="summary-box">
            المجموع : {{ $order['subtotal'] }}
        </div>

        <div class="summary-box total-box">
            الصافي للدفع (ش.ض) : {{ $order['grand_total'] }}
        </div>

        {{-- البار كود والسفلية --}}
        <div class="barcode-container">
            <svg id="barcode-svg" style="margin: 0 auto; max-width: 100%; height: 40px;"></svg>
            <div style="font-size: 8px; margin-top: 4px;">
                تاريخ ووقت الطباعة {{ $order['date'] }} {{ $order['time'] }}
            </div>
        </div>
    </div>

</div>
