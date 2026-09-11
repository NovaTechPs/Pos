<?php

use Livewire\Component;

new class extends Component
{
    public function printThermal()
    {
        $this->dispatch('do-silent-print', text: 'أهلاً بك في عالمنا');
    }
};
?>

<div class="p-6">
    <button
        wire:click="printThermal"
        class="px-5 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition shadow">
        طباعة حرارية
    </button>
</div>

@script
<script>
    $wire.on('do-silent-print', (data) => {
        const text = data.text || 'أهلاً بك في عالمنا';

        let iframe = document.getElementById('thermalFrame');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'thermalFrame';
            iframe.style.display = 'none';
            document.body.appendChild(iframe);
        }

        const doc = iframe.contentWindow.document;
        doc.open();
        doc.write('<html><head><style>@page { size: auto; margin: 0; } body { font-family: monospace, sans-serif; width: 80mm; margin: 0; padding: 10px; text-align: center; direction: rtl; } h2 { font-size: 16px; margin: 0; }</style></head><body><h2>' + text + '</h2></body></html>');
        doc.close();

        setTimeout(() => {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }, 300);
    });
</script>
@endscript
