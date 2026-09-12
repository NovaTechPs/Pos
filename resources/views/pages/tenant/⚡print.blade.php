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
        // نص بسيط جداً للتجربة
        let text = "اهلا بك\n\n\n";

        // إرسال النص مباشرة إلى RawBT
        window.location.href = "rawbt:text/" + encodeURIComponent(text);
    });
</script>
@endscript
