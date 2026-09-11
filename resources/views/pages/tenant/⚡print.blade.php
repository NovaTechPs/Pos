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

        // 1. بناء نص الفاتورة المنظم
        let text = "";
        text += "       " + inv.store_name + "       \n";
        text += "==============================\n";
        text += "رقم الفاتورة: " + inv.invoice_no + "\n";
        text += "التاريخ     : " + inv.date + "\n";
        text += "------------------------------\n";

        inv.items.forEach(item => {
            text += `${item.name}\n`;
            text += `   ${item.qty} x ${item.price.toFixed(2)} = ${(item.price * item.qty).toFixed(2)}\n`;
        });

        text += "------------------------------\n";
        text += "الإجمالي النهائي: " + inv.total.toFixed(2) + " شيكل\n";
        text += "==============================\n\n\n\n";

        // 2. تحويل النص العربي إلى ترميز Base64 آمن تماماً
        function utf8ToBase64(str) {
            return window.btoa(unescape(encodeURIComponent(str)));
        }

        const base64Data = utf8ToBase64(text);

        // 3. إرسال البيانات باستخدام بروتوكول RawBT اليدوي المخصص للطباعة الجرافيكية
        const intentUrl = "intent:#Intent;" +
            "scheme=rawbt;" +
            "package=ru.a402d.rawbtprinter;" +
            "S.data=" + encodeURIComponent(base64Data) + ";" +
            "S.type=text/plain;" +
            "end;";

        window.location.href = intentUrl;
    });
</script>
@endscript
