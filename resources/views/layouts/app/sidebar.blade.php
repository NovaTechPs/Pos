<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 dark:bg-zinc-900 text-zinc-800 dark:text-zinc-100" dir="rtl">

        <!-- هيدر المتجر المخصص للزبائن -->
        <header class="sticky top-0 z-40 bg-white/80 dark:bg-zinc-900/80 backdrop-blur-md border-b border-zinc-200 dark:border-zinc-800">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">

                <!-- شعار / اسم المتجر -->
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-emerald-500/10 rounded-lg text-emerald-600 dark:text-emerald-400">
                        <flux:icon icon="building-storefront" class="size-6" />
                    </div>
                    <div>
                        <flux:heading size="lg" class="font-bold">
                            {{ $title ?? 'المتجر الإلكتروني' }}
                        </flux:heading>
                    </div>
                </div>

                <!-- زر العودة أو الدعم إذا لزم الأمر -->
                <div class="flex items-center gap-3">
                    <flux:badge variant="subtle" class="hidden sm:inline-flex">
                        تسوق آمن ومباشر
                    </flux:badge>
                </div>

            </div>
        </header>

        <!-- المحتوى الرئيسي للصفحة -->
        <main class="py-8">
            {{ $slot }}
        </main>

        <!-- فوتر بسيط للمتجر -->
        <footer class="border-t border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 py-6 text-center text-xs text-zinc-500">
            <div class="max-w-7xl mx-auto px-4">
                جميع الحقوق محفوظة © {{ date('Y') }} - {{ $title ?? 'المتجر' }}
            </div>
        </footer>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
