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
                flex items-center justify-center gap-3
                rounded-xl
                bg-gray-900
                px-12 py-6
                text-2xl font-bold text-white
                shadow-lg
                transition
                hover:bg-gray-800
                active:scale-95
            "
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-8 w-8"
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
        document.addEventListener('livewire:init', function () {

            Livewire.on('print-halwo', function () {

                const text = "halwo\n\n\n";


                /*
                |--------------------------------------------------------------------------
                | 1 - AndroidPrinter
                |--------------------------------------------------------------------------
                */

                try {
                    if (
                        window.AndroidPrinter &&
                        typeof window.AndroidPrinter.printText === 'function'
                    ) {
                        window.AndroidPrinter.printText(text);
                        return;
                    }
                } catch (e) {
                    console.error('AndroidPrinter error:', e);
                }


                /*
                |--------------------------------------------------------------------------
                | 2 - NativePrinter
                |--------------------------------------------------------------------------
                */

                try {
                    if (
                        window.NativePrinter &&
                        typeof window.NativePrinter.printText === 'function'
                    ) {
                        window.NativePrinter.printText(text);
                        return;
                    }
                } catch (e) {
                    console.error('NativePrinter error:', e);
                }


                /*
                |--------------------------------------------------------------------------
                | 3 - Android
                |--------------------------------------------------------------------------
                */

                try {
                    if (
                        window.Android &&
                        typeof window.Android.printText === 'function'
                    ) {
                        window.Android.printText(text);
                        return;
                    }
                } catch (e) {
                    console.error('Android error:', e);
                }


                /*
                |--------------------------------------------------------------------------
                | 4 - Printer
                |--------------------------------------------------------------------------
                */

                try {
                    if (
                        window.Printer &&
                        typeof window.Printer.printText === 'function'
                    ) {
                        window.Printer.printText(text);
                        return;
                    }
                } catch (e) {
                    console.error('Printer error:', e);
                }


                /*
                |--------------------------------------------------------------------------
                | 5 - PrinterBridge
                |--------------------------------------------------------------------------
                */

                try {
                    if (
                        window.PrinterBridge &&
                        typeof window.PrinterBridge.printText === 'function'
                    ) {
                        window.PrinterBridge.printText(text);
                        return;
                    }
                } catch (e) {
                    console.error('PrinterBridge error:', e);
                }


                /*
                |--------------------------------------------------------------------------
                | 6 - AndroidBridge
                |--------------------------------------------------------------------------
                */

                try {
                    if (
                        window.AndroidBridge &&
                        typeof window.AndroidBridge.printText === 'function'
                    ) {
                        window.AndroidBridge.printText(text);
                        return;
                    }
                } catch (e) {
                    console.error('AndroidBridge error:', e);
                }


                /*
                |--------------------------------------------------------------------------
                | 7 - print
                |--------------------------------------------------------------------------
                */

                try {
                    if (
                        window.AndroidPrinter &&
                        typeof window.AndroidPrinter.print === 'function'
                    ) {
                        window.AndroidPrinter.print(text);
                        return;
                    }
                } catch (e) {
                    console.error('AndroidPrinter.print error:', e);
                }


                /*
                |--------------------------------------------------------------------------
                | لا يوجد Bridge
                |--------------------------------------------------------------------------
                */

                console.error(
                    'No Android printer bridge was found.'
                );

                alert(
                    'لم يتم العثور على اتصال الطابعة الداخلية داخل تطبيق Android.'
                );

            });

        });
    </script>

</div>
