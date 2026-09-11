<?php

use Livewire\Component;

new class extends Component
{
    public function printThermal()
    {
        $invoiceData = [
            'store_name' => 'UNITY STORE',
            'invoice_no' => 'INV-2026-001',
            'date'       => date('Y-m-d H:i'),
            'items'      => [
                ['name' => 'Test Item 1', 'qty' => 2, 'price' => 15.00],
                ['name' => 'Test Item 2', 'qty' => 1, 'price' => 20.00],
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
        Print Thermal Invoice (EN)
    </button>
</div>

@script
<script>
    $wire.on('do-kiosk-print', (event) => {
        const inv = event.data;

        // 1. بناء الفاتورة باللغة الإنجليزية
        let text = "";
        text += "==============================\n";
        text += "         " + inv.store_name + "        \n";
        text += "==============================\n";
        text += "Invoice No: " + inv.invoice_no + "\n";
        text += "Date      : " + inv.date + "\n";
        text += "------------------------------\n";
        text += "Item           Qty       Price\n";
        text += "------------------------------\n";

        inv.items.forEach(item => {
            let name = item.name.padEnd(14, ' ');
            let qty = (item.qty + "x").padEnd(8, ' ');
            let price = (item.price * item.qty).toFixed(2);
            text += `${name} ${qty} ${price}\n`;
        });

        text += "------------------------------\n";
        text += "TOTAL     : " + inv.total.toFixed(2) + " NIS\n";
        text += "==============================\n\n\n\n";

        // 2. إرسال النص إلى RawBT بروابط المباشرة
        const intentUrl = "intent:#Intent;" +
            "scheme=rawbt;" +
            "package=ru.a402d.rawbtprinter;" +
            "S.text=" + encodeURIComponent(text) + ";" +
            "end;";

        window.location.href = intentUrl;
    });
</script>
@endscript
