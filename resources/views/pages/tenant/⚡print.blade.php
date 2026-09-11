<?php

use Livewire\Component;

new class extends Component
{
    public function printThermal()
    {
        $invoiceData = [
            'store_name' => 'متجر الوحدة',
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

        // 1. بناء نص الفاتورة المنسق كـ ESC/POS
        let receipt = "";
        receipt += "================================\n";
        receipt += "        " + inv.store_name + "        \n";
        receipt += "================================\n";
        receipt += "رقم الفاتورة: " + inv.invoice_no + "\n";
        receipt += "التاريخ     : " + inv.date + "\n";
        receipt += "--------------------------------\n";
        receipt += "الصنف          الكمية     السعر \n";
        receipt += "--------------------------------\n";

        inv.items.forEach(item => {
            let name = item.name.padEnd(14, ' ');
            let qty = (item.qty + "x").padEnd(8, ' ');
            let price = (item.price * item.qty).toFixed(2);
            receipt += `${name} ${qty} ${price}\n`;
        });

        receipt += "--------------------------------\n";
        receipt += "الإجمالي النهائي: " + inv.total.toFixed(2) + "\n";
        receipt += "================================\n\n\n\n";

        // 2. إرسال النص مباشرة إلى تطبيق RawBT لطباعته صامتاً
        const intentUrl = "intent:#Intent;" +
            "scheme=rawbt;" +
            "package=ru.a404m.a200.a411;" +
            "S.text=" + encodeURIComponent(receipt) + ";" +
            "end;";

        window.location.href = intentUrl;
    });
</script>
@endscript
