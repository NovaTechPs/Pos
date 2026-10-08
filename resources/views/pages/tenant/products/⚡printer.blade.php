<?php

use Livewire\Component;

new class extends Component
{
    public string $productName = 'دراجة أطفال كهربائية S3';
    public string $barcode = '3312';
    public float $price = 120.00;

    public function printLabel(): void
    {
        $this->dispatch('trigger-print');
    }
};
?>

<div>
    <!-- الواجهة الرئيسية العادية (لن تظهر في الطباعة) -->
    <div class="p-6 max-w-md mx-auto bg-white rounded-xl shadow-md space-y-4 print:hidden" dir="rtl">
        <h2 class="text-xl font-bold text-gray-800 border-b pb-2">طباعة ملصق باركود</h2>

        <div class="space-y-2 text-sm text-gray-700">
            <p><span class="font-semibold">المنتج:</span> {{ $productName }}</p>
            <p><span class="font-semibold">الباركود:</span> {{ $barcode }}</p>
            <p><span class="font-semibold">السعر:</span> {{ $price }} ₪</p>
        </div>

        <button
            type="button"
            wire:click="printLabel"
            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg shadow transition flex items-center justify-center gap-2"
        >
            <span>🖨️</span>
            <span>طباعة الملصق</span>
        </button>
    </div>

    <!-- قسم الطباعة فقط (يظهر أثناء الطباعة وتختفي باقي عناصر الصفحة) -->
    <div class="hidden print:block print:w-full print:m-0 print:p-0" dir="rtl">
        <div class="label-box">
            <div class="product-title">{{ $productName }}</div>

            <div class="barcode-container">
                <svg id="barcode"></svg>
            </div>

            <div class="price-tag">السعر: {{ $price }} NIS</div>
        </div>
    </div>

    <!-- تنسيقات الطباعة الخاصة بالملصق -->
    <style>
        @media print {
            /* إخفاء الهيدر والفوتر والروابط الافتراضية للفيور */
            @page {
                size: auto;
                margin: 0mm;
            }

            body {
                margin: 0;
                padding: 0;
                background: white;
            }

            /* إخفاء أي عنصر غير مخصص للطباعة */
            body * {
                visibility: hidden;
            }

            /* إظهار قسم الملصق فقط */
            .print\:block, .print\:block * {
                visibility: visible;
            }

            .print\:block {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }

            /* تصميم بطاقة الملصق */
            .label-box {
                width: 58mm; /* يمكن تعديل العرض حسب عرض الورق لديك */
                padding: 4mm;
                text-align: center;
                box-sizing: border-box;
                margin: 0 auto;
            }

            .product-title {
                font-size: 11px;
                font-weight: bold;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                margin-bottom: 2mm;
            }

            .barcode-container svg {
                max-width: 100%;
                height: 35px;
            }

            .price-tag {
                font-size: 12px;
                font-weight: bold;
                margin-top: 2mm;
            }
        }
    </style>

    <!-- مكتبة توليد الباركود JS -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.min.js"></script>
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('trigger-print', () => {
                // توليد الباركود داخل SVG
                JsBarcode("#barcode", @js($barcode), {
                    format: "CODE128",
                    width: 1.8,
                    height: 35,
                    displayValue: true,
                    fontSize: 10,
                    margin: 0
                });

                // تشغيل امر الطباعة مباشرة
                setTimeout(() => {
                    window.print();
                }, 200);
            });
        });
    </script>
</div>
