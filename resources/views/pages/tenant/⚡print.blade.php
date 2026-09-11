<?php

use Livewire\Component;

new class extends Component
{
    public function printThermal()
    {
        $invoiceData = [
            'store_name' => 'مستودع الوحدة',
            'invoice_no' => 'INV-2026-001',
            'date'       => date('Y-m-d H:i'),
            'items'      => [
                ['name' => 'منتج تجريبي 1', 'qty' => 2, 'price' => 15.00],
                ['name' => 'منتج تجريبي 2', 'qty' => 1, 'price' => 20.00],
            ],
            'subtotal'   => 50.00,
            'tax'        => 5.00,
            'total'      => 55.00,
        ];

        $this->dispatch('do-kiosk-print', data: $invoiceData);
    }
};
?>

<div class="p-6">
    <button
        wire:click="printThermal"
        class="px-5 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition shadow">
        طباعة فاتورة عربية صامتة
    </button>
</div>

@script
<script>
    $wire.on('do-kiosk-print', (event) => {
        const inv = event.data;

        // 1. إنشاء Canvas افتراضي برسم عالي الدقة للطباعة الحرارية 58mm
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        // عرض 384 بكسل يتطابق تماماً مع رأس الطباعة الحرارية (58mm)
        canvas.width = 384;

        // حساب الارتفاع المطلوب ديناميكياً
        const lineHeight = 32;
        const totalLines = 8 + inv.items.length;
        canvas.height = totalLines * lineHeight + 80;

        // خلفية بيضاء ونصوص سوداء حادة
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000000';
        ctx.direction = 'rtl';

        let y = 40;

        // --- اسم المتجر (عنوان بارز) ---
        ctx.textAlign = 'center';
        ctx.font = 'bold 24px Arial, sans-serif';
        ctx.fillText(inv.store_name, canvas.width / 2, y);
        y += lineHeight + 5;

        // --- تفاصيل الفاتورة ---
        ctx.font = '16px Arial, sans-serif';
        ctx.fillText("رقم الفاتورة: " + inv.invoice_no, canvas.width / 2, y);
        y += lineHeight;
        ctx.fillText("التاريخ: " + inv.date, canvas.width / 2, y);
        y += lineHeight;

        // --- خط فاصل ---
        ctx.beginPath();
        ctx.lineWidth = 2;
        ctx.moveTo(10, y);
        ctx.lineTo(374, y);
        ctx.stroke();
        y += 25;

        // --- الأصناف (الاسم محاذاة لليمين والسعر لليسار) ---
        ctx.font = '16px Arial, sans-serif';
        inv.items.forEach(item => {
            const itemTotal = (item.price * item.qty).toFixed(2);

            ctx.textAlign = 'right';
            ctx.fillText(`${item.name} (x${item.qty})`, 374, y);

            ctx.textAlign = 'left';
            ctx.fillText(`${itemTotal} شيكل`, 10, y);

            y += lineHeight;
        });

        // --- خط فاصل ---
        ctx.beginPath();
        ctx.lineWidth = 2;
        ctx.moveTo(10, y);
        ctx.lineTo(374, y);
        ctx.stroke();
        y += 30;

        // --- الإجمالي النهائي ---
        ctx.font = 'bold 20px Arial, sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText("الإجمالي النهائي:", 374, y);

        ctx.textAlign = 'left';
        ctx.fillText(`${inv.total.toFixed(2)} شيكل`, 10, y);

        // 2. استخراج صورة PNG بصيغة Base64
        const dataUrl = canvas.toDataURL('image/png');
        const base64Image = dataUrl.replace(/^data:image\/png;base64,/, "");

        // 3. إرسال الصورة مباشرة إلى RawBT لطباعتها صامتاً وبدقة عالية
        window.location.href = "rawbt:base64," + base64Image;
    });
</script>
@endscript
