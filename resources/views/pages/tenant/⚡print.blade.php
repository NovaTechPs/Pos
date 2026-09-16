<?php

use Livewire\Component;

new class extends Component
{
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
        $this->dispatch('trigger-rawbt-direct');
    }
};
?>

<div class="p-6">

    {{-- زر الطباعة --}}
    <button wire:click="printInvoice" class="px-5 py-2.5 bg-green-600 text-white font-bold rounded-lg shadow hover:bg-green-700 transition">
        طباعة الفاتورة صامتاً 🖨️
    </button>

    {{-- قالب الفاتورة الحرارية --}}
    <div id="receipt-content" class="hidden">
        <div style="width: 58mm; padding: 0; font-family: Arial, sans-serif; font-size: 11px; color: #000; direction: rtl; text-align: center;">

            <div style="margin-bottom: 5px;">
                <div style="font-size: 16px; font-weight: bold;">{{ $order['store_name'] }}</div>
                <div style="font-size: 10px; margin: 2px 0;">راجع فاتورتك وتأكد من مشترياتك قبل مغادرة المعرض</div>
                <div style="font-size: 10px; font-weight: bold;">النسخة الأصلية</div>
            </div>

            <div style="display: flex; justify-content: space-between; font-size: 10px; margin: 6px 0 4px 0; padding: 0 2px;">
                <span>{{ $order['id'] }}</span>
                <span>{{ $order['date'] }}</span>
                <span>{{ $order['time'] }}</span>
            </div>

            <table style="width: 100%; border-collapse: collapse; margin-top: 4px;">
                <thead>
                    <tr>
                        <th style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; width: 8%;">#</th>
                        <th style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; width: 50%;">البيان</th>
                        <th style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; width: 12%;">كمية</th>
                        <th style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; width: 15%;">سعر</th>
                        <th style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; width: 15%;">مبلغ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order['items'] as $index => $item)
                        <tr>
                            <td style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; text-align: center;">{{ $index + 1 }}</td>
                            <td style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; text-align: right;">{{ $item['name'] }}</td>
                            <td style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; text-align: center;">{{ $item['qty'] }}</td>
                            <td style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; text-align: center;">{{ $item['price'] }}</td>
                            <td style="border: 1px solid #000; padding: 3px 2px; font-size: 10px; text-align: center;">{{ $item['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div style="border: 1px solid #000; padding: 4px; margin-top: 4px; font-size: 11px; font-weight: bold; text-align: right;">
                مجموع الكميات : {{ $order['total_qty'] }}
            </div>

            <div style="border: 1px solid #000; padding: 4px; margin-top: 4px; font-size: 11px; font-weight: bold; text-align: right;">
                المجموع : {{ $order['subtotal'] }}
            </div>

            <div style="border: 2px solid #000; padding: 6px; margin-top: 5px; font-size: 12px; font-weight: bold; text-align: center;">
                الصافي للدفع (ش.ض) : {{ $order['grand_total'] }}
            </div>

            <div style="margin-top: 8px;">
                <div style="font-size: 8px; margin-top: 4px;">
                    تاريخ ووقت الطباعة {{ $order['date'] }} {{ $order['time'] }}
                </div>
            </div>

        </div>
    </div>

    {{-- Script إرسال بروتوكول rawbt: المباشر --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('trigger-rawbt-direct', () => {
                const element = document.getElementById('receipt-content');
                if (!element) return;

                const receiptHtml = element.innerHTML;

                // تجهيز كود HTML كامل للفاتورة فقط
                const cleanHtml = `
                    <!DOCTYPE html>
                    <html dir="rtl">
                    <head>
                        <meta charset="utf-8">
                        <style>
                            body { margin: 0; padding: 0; width: 58mm; font-family: Arial, sans-serif; }
                        </style>
                    </head>
                    <body>
                        ${receiptHtml}
                    </body>
                    </html>
                `;

                // استخدام بروتوكول rawbt:base64 المباشر
                const base64Data = btoa(unescape(encodeURIComponent(cleanHtml)));

                // توجيه المتصفح لـ rawbt مباشرة
                window.location.href = `rawbt:base64,${base64Data}`;
            });
        });
    </script>
</div>
