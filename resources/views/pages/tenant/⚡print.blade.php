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
    <!-- زر الطباعة -->
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

        // 1. إنشاء أو جلب الـ iframe المخفي
        let iframe = document.getElementById('silentPrintFrame');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'silentPrintFrame';
            iframe.style.position = 'absolute';
            iframe.style.width = '0px';
            iframe.style.height = '0px';
            iframe.style.border = 'none';
            document.body.appendChild(iframe);
        }

        // 2. بناء هيكل الفاتورة للطباعة الحرارية (58mm)
        const doc = iframe.contentWindow.document;
        doc.open();

        let htmlContent = '<html><head><style>';
        htmlContent += '@page { size: 58mm auto; margin: 0; }';
        htmlContent += 'body { font-family: monospace, sans-serif; width: 58mm; margin: 0; padding: 5px; direction: rtl; text-align: center; font-size: 12px; }';
        htmlContent += 'table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 5px; }';
        htmlContent += 'th, td { text-align: right; padding: 2px 0; }';
        htmlContent += '.divider { border-top: 1px dashed #000; margin: 5px 0; }';
        htmlContent += '.total-box { font-weight: bold; font-size: 13px; margin-top: 5px; }';
        htmlContent += '</style></head><body>';

        htmlContent += '<h3>' + inv.store_name + '</h3>';
        htmlContent += '<div>رقم الفاتورة: ' + inv.invoice_no + '</div>';
        htmlContent += '<div>التاريخ: ' + inv.date + '</div>';
        htmlContent += '<div class="divider"></div>';

        htmlContent += '<table><thead><tr><th>الصنف</th><th>الكمية</th><th>السعر</th></tr></thead><tbody>';
        inv.items.forEach(item => {
            htmlContent += '<tr><td>' + item.name + '</td><td>' + item.qty + '</td><td>' + (item.price * item.qty).toFixed(2) + '</td></tr>';
        });
        htmlContent += 'tbody></table>';

        htmlContent += '<div class="divider"></div>';
        htmlContent += '<div class="total-box">الإجمالي: ' + inv.total.toFixed(2) + '</div>';
        htmlContent += '<br><br>'; // مسافة لقص الورق
        htmlContent += '</body></html>';

        doc.write(htmlContent);
        doc.close();

        // 3. استدعاء أمر الطباعة التلقائي
        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 300);
    });
</script>
@endscript
