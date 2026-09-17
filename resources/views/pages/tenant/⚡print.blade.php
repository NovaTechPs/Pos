<?php

use Livewire\Component;

new class extends Component
{
    public array $invoice = [];

    public function mount()
    {
        // بيانات الفاتورة
        $this->invoice = [
            'number' => '100035970',
            'date' => '14/09/2026',
            'time' => '05:33',
            'items' => [
                [
                    'name' => 'مج زجاج مع غطاء خشب + حبه s17 مصاصة',
                    'qty' => 1,
                    'price' => 10,
                    'total' => 10,
                ]
            ],
            'total_qty' => 1,
            'total_amount' => 10,
        ];
    }
}; ?>

<div>
    <!-- مكتبة QZ Tray و RSVP -->
    <script src="https://cdn.jsdelivr.net/npm/rsvp@4/dist/rsvp.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.min.js"></script>

    <!-- أزرار التحكم -->
    <div class="no-print p-4 flex gap-2">
        <button type="button" onclick="printInvoiceSilent()" class="px-4 py-2 bg-green-600 text-white rounded-lg shadow hover:bg-green-700">
            طباعة صامتة (QZ Tray)
        </button>
        <button type="button" onclick="window.print()" class="px-4 py-2 bg-gray-500 text-white rounded-lg shadow hover:bg-gray-600">
            طباعة المتصفح العادية
        </button>
    </div>

    <!-- قالب الفاتورة الحرارية -->
    <div id="receipt" class="receipt-container">
        <div class="header">
            <h2>فانوس</h2>
            <p>راجع فاتورتك وتأكد من مشترياتك قبل مغادرة المعرض</p>
            <p><strong>النسخة الأصلية</strong></p>
        </div>

        <div class="meta-info">
            <span>{{ $invoice['number'] }}</span>
            <span>{{ $invoice['date'] }} {{ $invoice['time'] }}</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 10%;">#</th>
                    <th style="width: 50%;">البيان</th>
                    <th style="width: 15%;">الكمية</th>
                    <th style="width: 12%;">السعر</th>
                    <th style="width: 13%;">المبلغ</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice['items'] as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['qty'] }}</td>
                    <td>{{ $item['price'] }}</td>
                    <td>{{ $item['total'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="summary">
            <p><strong>مجموع الكميات:</strong> {{ $invoice['total_qty'] }}</p>
            <p><strong>المجموع:</strong> {{ $invoice['total_amount'] }}</p>
        </div>

        <div class="grand-total">
            الصافي للدفع (ش.ض): {{ $invoice['total_amount'] }}
        </div>

        <div class="footer">
            <p>الشامل لايت للمحاسبة</p>
            <p>تاريخ ووقت الطباعة: {{ $invoice['date'] }} {{ $invoice['time'] }} م</p>
        </div>
    </div>

    <!-- تنسيقات CSS الخاصّة بالطابعة -->
    <style>
        .receipt-container {
            width: 80mm;
            padding: 5mm;
            background: #fff;
            color: #000;
            font-family: Tahoma, 'Courier New', monospace;
            font-size: 12px;
            direction: rtl;
            border: 1px solid #ccc;
            margin: 10px auto;
            box-sizing: border-box;
        }

        .receipt-container .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 5px; margin-bottom: 5px; }
        .receipt-container .header h2 { margin: 0; font-size: 18px; font-weight: bold; }
        .receipt-container .header p { margin: 2px 0; font-size: 10px; }

        .receipt-container .meta-info { display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 5px; font-size: 11px; }

        .receipt-container table { width: 100%; border-collapse: collapse; text-align: center; margin-bottom: 5px; }
        .receipt-container th, .receipt-container td { border: 1px solid #000; padding: 3px 1px; font-size: 10px; }

        .receipt-container .summary { border-top: 1px dashed #000; padding-top: 5px; font-size: 11px; }
        .receipt-container .summary p { margin: 2px 0; }

        .receipt-container .grand-total { border: 2px solid #000; text-align: center; font-weight: bold; padding: 4px; font-size: 13px; margin: 5px 0; }

        .receipt-container .footer { text-align: center; font-size: 9px; margin-top: 5px; }

        @media print {
            body * { visibility: hidden; }
            .no-print { display: none !important; }
            .receipt-container, .receipt-container * { visibility: visible; }
            .receipt-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 80mm !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
            }
            @page { size: 80mm auto; margin: 0mm; }
        }
    </style>

    <!-- عزل السكربت تماماً لحل مشكلة قراءة Blade له كنص -->
    @script
    <script>
        window.printInvoiceSilent = async function() {
            try {
                if (!qz.websocket.isActive()) {
                    await qz.websocket.connect();
                }

                const printElement = document.getElementById('receipt');
                const htmlContent = printElement.outerHTML;
                const styleElement = document.querySelector('style');
                const styleContent = styleElement ? styleElement.innerHTML : '';

                const printer = await qz.printers.getDefault();
                const config = qz.configs.create(printer, {
                    size: { width: 80, mm: true },
                    margins: 0
                });

                const data = [{
                    type: 'pixel',
                    format: 'html',
                    flavor: 'plain',
                    data: '<html><head><meta charset="UTF-8"><style>body { margin: 0; padding: 0; direction: rtl; } ' + styleContent + '</style></head><body>' + htmlContent + '</body></html>'
                }];

                await qz.print(config, data);

            } catch (err) {
                console.error("خطأ في الطباعة الصامتة:", err);
                alert("لم يتم الاتصال ببرنامج QZ Tray أو تعذر تحديد الطابعة.");
            }
        }
    </script>
    @endscript
</div>
