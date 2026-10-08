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
    <!-- الواجهة الرئيسية العادية (مخفية أثناء الطباعة) -->
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

    <!-- قسم الطباعة فقط -->
    <div class="hidden print:block print:w-full print:m-0 print:p-0" dir="rtl">
        <div class="label-box">
            <div class="product-title">{{ $productName }}</div>

            <div class="barcode-container">
                <svg id="barcode"></svg>
            </div>

            <div class="price-tag">السعر: {{ $price }} NIS</div>
        </div>
    </div>

    <!-- تنسيقات طباعة مصغرة لمنع الانقسام -->
    <style>
        @media print {
            @page {
                size: 50mm 25mm; /* يمكن تعديل الأبعاد حسب حجم رول ملصقك */
                margin: 0;
            }

            html, body {
                width: 50mm;
                height: 25mm;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden;
            }

            body * {
                visibility: hidden;
            }

            .print\:block, .print\:block * {
                visibility: visible;
            }

            .print\:block {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
            }

            .label-box {
                width: 100%;
                height: 100%;
                padding: 1.5mm;
                text-align: center;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                align-items: center;
            }

            .product-title {
                font-size: 8.5pt;
                font-weight: bold;
                line-height: 1.1;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 100%;
            }

            .barcode-container {
                display: flex;
                justify-content: center;
                align-items: center;
                width: 100%;
            }

            .barcode-container svg {
                max-width: 100%;
                max-height: 14mm;
            }

            .price-tag {
                font-size: 9pt;
                font-weight: bold;
                line-height: 1;
            }
        }
    </style>

    <!-- مكتبة توليد الباركود -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.min.js"></script>
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('trigger-print', () => {
                // توليد باركود بارتفاع وأبعاد مصغرة تناسب الملصق
                JsBarcode("#barcode", @js($barcode), {
                    format: "CODE128",
                    width: 1.2,
                    height: 25,
                    displayValue: true,
                    fontSize: 8,
                    margin: 0
                });

                setTimeout(() => {
                    window.print();
                }, 150);
            });
        });
    </script>
</div>
