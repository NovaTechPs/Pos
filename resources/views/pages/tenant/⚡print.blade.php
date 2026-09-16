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
        $this->dispatch('trigger-rawbt-print');
    }
};
?>

<div class="p-6" x-data>

    <div class="flex gap-3">
        {{-- زر الطباعة الرئيسي --}}
        <button wire:click="printInvoice" class="px-5 py-2.5 bg-green-600 text-white font-bold rounded-lg shadow hover:bg-green-700 transition">
            طباعة صامتة (RawBT) 🖨️
        </button>

        {{-- زر للاختبار المباشر لتأكد من تثبيت وتجاوب RawBT --}}
        <a href="intent:#Intent;scheme=rawbt;package=ru.a404m.rawbtprinter;end;" class="px-4 py-2.5 bg-gray-600 text-white font-medium rounded-lg shadow hover:bg-gray-700 text-sm flex items-center">
            اختبار فتح RawBT
        </a>
    </div>

    {{-- قالب الفاتورة الحرارية --}}
    <div id="receipt-content" class="hidden">
        <div style="width: 58mm; padding: 2mm 0; font-family: Arial, sans-serif; font-size: 11px; color: #000; direction: rtl; text-align: center;">

            <div style="margin-bottom: 5px;">
                <div style="font-size: 16px; font-weight: bold;">{{ $order['store_name'] }}</div>
                <div style="font-size: 10px; margin: 2px 0;">راجع فاتورتك وتأكد من مشترياتك قبل مغادرة المعرض</div>
                <div style="font-size: 10px; font-weight: bold;">النسخة الأصلية</div>
            </div>

            <div style="display: flex; justify-content: space-between; font-size: 10px; margin: 6px 0 4px 0;">
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

    {{-- Script المعالج لطباعة أندرويد والكمبيوتر --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('trigger-rawbt-print', () => {
                const element = document.getElementById('receipt-content');
                if (!element) return;

                element.classList.remove('hidden');

                const isAndroid = /android/i.test(navigator.userAgent);

                if (isAndroid) {
                    try {
                        const htmlContent = element.innerHTML;

                        // معالجة الأحرف العربية وتحويلها بأمان إلى Base64
                        const base64Html = btoa(encodeURIComponent(htmlContent).replace(/%([0-9A-F]{2})/g,
                            function toSolidBytes(match, p1) {
                                return String.fromCharCode('0x' + p1);
                            }
                        ));

                        const rawbtIntent = `intent:${base64Html}#Intent;scheme=rawbt;type=text/html;base64=true;package=ru.a404m.rawbtprinter;S.txt=;end;`;

                        element.classList.add('hidden');

                        // توجيه الأمر لـ RawBT
                        window.location.href = rawbtIntent;
                    } catch (e) {
                        console.error('خطأ في تحويل الفاتورة لـ RawBT:', e);
                        window.print();
                        element.classList.add('hidden');
                    }
                } else {
                    // إذا كنت تعمل من متصفح كمبيوتر للتجربة
                    window.print();
                    setTimeout(() => element.classList.add('hidden'), 500);
                }
            });
        });
    </script>
</div>
