<x-layouts::tenant :title="__('Dashboard')">
    <flux:main>
        <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">

            <!-- 1. كروت إحصائيات سريعة للـ POS -->
            <div class="grid auto-rows-min gap-4 md:grid-cols-3">

                <!-- كارت إجمالي مبيعات اليوم -->
                <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-neutral-500 dark:text-neutral-400">إجمالي مبيعات اليوم</span>
                        <flux:icon.banknotes class="size-5 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white font-mono">
                        ₪0.00
                    </div>
                </div>

                <!-- كارت عدد الفواتير -->
                <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-neutral-500 dark:text-neutral-400">عدد الفواتير</span>
                        <flux:icon.receipt-percent class="size-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div class="mt-2 text-2xl font-black text-neutral-900 dark:text-white font-mono">
                        0
                    </div>
                </div>

                <!-- كارت حالة النظام -->
                <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-neutral-500 dark:text-neutral-400">حالة الصندوق</span>
                        <flux:icon.sparkles class="size-5 text-amber-500" />
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <span class="inline-block size-2.5 rounded-full bg-emerald-500"></span>
                        <span class="text-sm font-bold text-neutral-700 dark:text-neutral-200">مفتوح للبيع</span>
                    </div>
                </div>
            </div>

            <!-- 2. الجزء الرئيسي: شاشة سلة البيع التفاعلية -->
            <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-4">

                <!-- المكون المدمج (Single-File Component) -->

            </div>
        </div>
    </flux:main>
</x-layouts::tenant>
