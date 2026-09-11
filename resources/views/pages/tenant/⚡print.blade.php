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

        $this->dispatch('do-direct-print', data: $invoiceData);
    }
};
?>

<div class="p-6" x-data="{ port: null }">
    <!-- زر الربط بالطابعة المباشرة (يُضغط مرة واحدة فقط للربط) -->
    <button
        @click="
            if ('serial' in navigator) {
                navigator.serial.requestPort().then(p => {
                    port = p;
                    alert('تم الربط بالطابعة بنجاح!');
                }).catch(e => alert('خطأ في الاتصال: ' + e));
            } else {
                alert('المتصفح لا يدعم Web Serial API');
            }
        "
        class="px-4 py-2 bg-gray-700 text-white font-medium rounded-lg hover:bg-gray-800 transition mb-3">
        ربط الطابعة الداخلية (مرة واحدة)
    </button>

    <br>

    <!-- زر الطباعة الصامتة المباشرة -->
    <button
        wire:click="printThermal"
        class="px-5 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition shadow">
        طباعة حرارية مباشرة
    </button>
</div>

@script
<script>
    let activePort = null;

    $wire.on('do-direct-print', async (event) => {
        const inv = event.data;

        // تجهيز بيانات الفاتورة
        let text = "================================\n";
        text += "        " + inv.store_name + "        \n";
        text += "   أهلاً بك في عالمنا - POS   \n";
        text += "================================\n";
        text += "رقم الفاتورة : " + inv.invoice_no + "\n";
        text += "التاريخ      : " + inv.date + "\n";
        text += "--------------------------------\n";
        text += "الصنف          الكمية     السعر \n";
        text += "--------------------------------\n";

        inv.items.forEach(item => {
            let name = item.name.padEnd(14, ' ');
            let qty = (item.qty + "x").padEnd(8, ' ');
            let price = (item.price * item.qty).toFixed(2);
            text += `${name} ${qty} ${price}\n`;
        });

        text += "--------------------------------\n";
        text += "الإجمالي النهائي: " + inv.total.toFixed(2) + "\n";
        text += "================================\n\n\n\n"; // مسافة لقص الورق

        try {
            // استخدام المنفذ المربوط سابقاً أو طلب المنفذ
            if (!activePort) {
                const ports = await navigator.serial.getPorts();
                if (ports.length > 0) {
                    activePort = ports[0];
                } else {
                    activePort = await navigator.serial.requestPort();
                }
            }

            // فتح المنفذ وإرسال البيانات مباشرة
            if (!activePort.readable) {
                await activePort.open({ baudRate: 9600 }); // معدل السرعة الافتراضي لأجهزة POS
            }

            const encoder = new TextEncoder();
            const writer = activePort.writable.getWriter();
            await writer.write(encoder.encode(text));
            writer.releaseLock();

        } catch (error) {
            console.error("فشلت الطباعة المباشرة:", error);
            alert("تعذر الاتصال بالطابعة المباشرة. تأكد من تفعيل Web Serial أو اضغط زر الربط.");
        }
    });
</script>
@endscript
