<?php

use Livewire\Component;

new class extends Component {
    public $order = [
        'id' => '#10024',
        'date' => '2026-03-29 14:30',
        'items' => [
            ['name' => 'منتج 1', 'qty' => 2, 'price' => 15],
            ['name' => 'منتج 2', 'qty' => 1, 'price' => 50],
        ],
        'total' => 80
    ];

    public function printReceipt()
    {
        // إرسال حدث لـ JavaScript لتشغيل الطباعة تلقائياً
        $this->dispatch('trigger-print');
    }
};
?>

<div>
    <!-- زر الطباعة -->
    <button wire:click="printReceipt" class="px-4 py-2 bg-blue-600 text-white rounded font-bold">
        طباعة الفاتورة
    </button>

    <!-- منطقة الفاتورة القابلة للطباعة -->
    <div id="printable-receipt" class="receipt-container">
        <div class="header">
            <h2>اسم المتجر / POS Store</h2>
            <p>فاتورة مبيعات</p>
            <p>رقم الفاتورة: {{ $order['id'] }}</p>
            <p>التاريخ: {{ $order['date'] }}</p>
        </div>

        <hr>

        <table>
            <thead>
                <tr>
                    <th>الصنف</th>
                    <th>العدد</th>
                    <th>السعر</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order['items'] as $item)
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['qty'] }}</td>
                    <td>{{ $item['price'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <hr>

        <div class="total">
            <strong>المجموع الكلي: {{ $order['total'] }} $</strong>
        </div>

        <div class="footer">
            <p>شكراً لزيارتكم!</p>
        </div>
    </div>

    <!-- تنسيقات الطباعة (CSS) -->
    <style>
        /* التنسيق الافتراضي على الشاشة */
        .receipt-container {
            width: 80mm; /* عرض ورق الفواتير الحراري القياسي */
            background: #fff;
            padding: 10px;
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin-top: 20px;
            border: 1px solid #ccc;
        }

        .receipt-container table {
            width: 100%;
            text-align: right;
            border-collapse: collapse;
        }

        .receipt-container th, .receipt-container td {
            padding: 4px 0;
        }

        .receipt-container .header, .receipt-container .footer {
            text-align: center;
        }

        /* تنسيقات أثناء الطباعة فقط */
        @media print {
            /* إخفاء كل عناصر الصفحة ما عدا الفاتورة */
            body * {
                visibility: hidden;
            }
            #printable-receipt, #printable-receipt * {
                visibility: visible;
            }
            #printable-receipt {
                position: absolute;
                left: 0;
                top: 0;
                width: 80mm; /* ضبط الحجم تماماً لعرض الورق الحراري */
                margin: 0;
                padding: 0;
                border: none;
            }

            /* إلغاء الهوامش الافتراضية للفيلم/الصفحة */
            @page {
                size: 80mm auto;
                margin: 0;
            }
        }
    </style>

    <!-- استقبال الحدث وتشغيل أمر الطباعة -->
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('trigger-print', () => {
                window.print();
            });
        });
    </script>
</div>
