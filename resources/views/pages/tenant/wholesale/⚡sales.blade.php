<?php

use Livewire\Component;

new class extends Component
{
    public function print()
    {
        $this->dispatch('print-halwo');
    }
};
?>

<div
    class="min-h-screen w-full flex items-center justify-center"
    dir="rtl"
>
    <div class="flex items-center justify-center">

        <button
            type="button"
            wire:click="print"
            class="
                flex
                items-center
                justify-center
                gap-3
                px-10
                py-5
                rounded-xl
                bg-gray-900
                text-white
                text-2xl
                font-bold
                shadow-lg
                hover:bg-gray-800
                active:scale-95
                transition
            "
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="w-8 h-8"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 9V4h12v5M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v6H6v-6z"
                />
            </svg>

            <span>طباعة</span>
        </button>

    </div>


    <script>
        document.addEventListener('livewire:init', () => {

            Livewire.on('print-halwo', () => {

                const text = "halwo\n\n\n";


                // AndroidPrinter
                if (
                    window.AndroidPrinter &&
                    typeof window.AndroidPrinter.printText === 'function'
                ) {
                    window.AndroidPrinter.printText(text);
                    return;
                }


                // NativePrinter
                if (
                    window.NativePrinter &&
                    typeof window.NativePrinter.printText === 'function'
                ) {
                    window.NativePrinter.printText(text);
                    return;
                }


                // Android
                if (
                    window.Android &&
                    typeof window.Android.printText === 'function'
                ) {
                    window.Android.printText(text);
                    return;
                }


                // لا يوجد Printer Bridge
                alert(
                    'الطابعة الداخلية غير متصلة بتطبيق الجهاز.\n\n' +
                    'يجب تشغيل نسخة Android التي تحتوي على Printer Bridge.'
                );

            });

        });
    </script>

</div>
