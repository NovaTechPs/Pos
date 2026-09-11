<?php

use Livewire\Component;

new class extends Component
{
    public function printThermal()
    {
        // إطلاق حدث للـ JavaScript لتنفيذ الطباعة المخفية
        $this->dispatch('do-silent-print', text: 'أهلاً بك في عالمنا');
    }
};
?>

<div class="p-4">
    <!-- زر الطباعة -->
    <button
        wire:click="printThermal"
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
        طباعة حرارية
    </button>

    <!-- عنصر iframe مخفي للطباعة الصامتة -->
    <iframe id="thermalFrame" style="display:none;"></iframe>

    <!-- سكربت استقبال الحدث وطباعته -->
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('do-silent-print', (data) => {
                const printText = data.text;
                const iframe = document.getElementById('thermalFrame');
                const doc = iframe.contentWindow.document;

                // التنسيق الخاص بالحراري (عرض 80mm أو 58mm)
                doc.open();
                doc.write(`
                    <html>
                        <head>
                            <style>
                                @page { size: auto; margin: 0; }
                                body {
                                    font-family: monospace, sans-serif;
                                    width: 80mm;
                                    margin: 0;
                                    padding: 10px;
                                    text-align: center;
                                    direction: rtl;
                                }
                                h2 { font-size: 16px; margin: 0; }
                            </style>
                        </head>
                        <body>
                            <h2>${printText}</h2>
                        </body>
                    </html>
                `);
                doc.close();

                // تنفيذ الطباعة
                setTimeout(() => {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 200);
            });
        });
    </script>
</div>
