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
        طباعة حرارية مباشرة
    </button>
</div>

@script
<script>
    $wire.on('do-kiosk-print', (event) => {
        const inv = event.data;

        // 1. إنشاء عنصر Canvas افتراضي في الذاكرة لتجميع الفاتورة كصورة
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        // عرض الورقة الحرارية (58mm تعادل تقريباً 384 بكسل)
        canvas.width = 384;

        // حساب الارتفاع الديناميكي
        const lineHeight = 30;
        const totalLines = 8 + inv.items.length;
        canvas.height = totalLines * lineHeight + 60;

        // خلفية بيضاء ونص أسود
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000000';
        ctx.direction = 'rtl';
        ctx.textAlign = 'center';

        let y = 35;

        // الهيدر
        ctx.font = 'bold 22px Tahoma, Arial';
        ctx.fillText(inv.store_name, canvas.width / 2, y);
        y += lineHeight;

        ctx.font = '16px Tahoma, Arial';
        ctx.fillText("رقم الفاتورة: " + inv.invoice_no, canvas.width / 2, y);
        y += lineHeight;
        ctx.fillText("التاريخ: " + inv.date, canvas.width / 2, y);
        y += lineHeight;

        // خط فاصل
        ctx.beginPath();
        ctx.moveTo(10, y);
        ctx.lineTo(374, y);
        ctx.stroke();
        y += 25;

        // الأصناف
        ctx.textAlign = 'right';
        ctx.font = '15px Tahoma, Arial';
        inv.items.forEach(item => {
            const totalItemPrice = (item.price * item.qty).toFixed(2);
            ctx.fillText(`${item.name} x${item.qty}`, 370, y);
            ctx.textAlign = 'left';
            ctx.fillText(`${totalItemPrice} شيكل`, 10, y);
            ctx.textAlign = 'right';
            y += lineHeight;
        });

        // خط فاصل
        ctx.beginPath();
        ctx.moveTo(10, y);
        ctx.lineTo(374, y);
        ctx.stroke();
        y += 25;

        // الإجمالي
        ctx.font = 'bold 18px Tahoma, Arial';
        ctx.fillText("الإجمالي النهائي:", 370, y);
        ctx.textAlign = 'left';
        ctx.fillText(`${inv.total.toFixed(2)} شيكل`, 10, y);

        // 2. تحويل الـ Canvas إلى Base64 ورَفعه عبر Intent إلى RawBT
        const base64Image = canvas.toDataURL('image/png').replace(/^data:image\/png;base64,/, "");

        const intentUrl = "intent:#Intent;" +
            "scheme=rawbt;" +
            "package=ru.a402d.rawbtprinter;" +
            "S.base64=" + base64Image + ";" +
            "end;";

        window.location.href = intentUrl;
    });
</script>
@endscript
