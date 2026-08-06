<x-layouts::saas :title="__('SaaS Dashboard')">
    <flux:main>
        @php
            $stats = [

                'total_tenants'  => 1,//\App\Models\Tenant::count(),
            'active_tenants' => \App\Models\Tenant::where('is_active', true)->count(),
                'trial_tenants'  => 111,//\App\Models\Tenant::where('status', 'trial')->count(),
                'total_users'    => 1111,//\App\Models\User::whereNotNull('tenant_id')->count(),
            ];

            $recentTenants = \App\Models\Tenant::latest()->take(5)->get();
        @endphp

        <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
            <!-- الشبكة العلوية: كروت الإحصائيات -->
            <div class="grid auto-rows-min gap-4 md:grid-cols-3">

                <!-- الكرت الأول -->
                <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-zinc-800 p-6 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <flux:subheading>إجمالي المتاجر</flux:subheading>
                        <flux:badge color="indigo" size="sm">SaaS</flux:badge>
                    </div>
                    <div class="mt-4">
                        <flux:heading size="2xl" class="font-bold">{{ $stats['total_tenants'] }}</flux:heading>
                        <p class="text-xs text-neutral-500 mt-1">مشترك مسجل في المنصة</p>
                    </div>
                </div>

                <!-- الكرت الثاني -->
                <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-zinc-800 p-6 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <flux:subheading class="text-emerald-600 dark:text-emerald-400">الاشتراكات النشطة</flux:subheading>
                        <flux:badge color="emerald" size="sm">Active</flux:badge>
                    </div>
                    <div class="mt-4">
                        <flux:heading size="2xl" class="font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['active_tenants'] }}</flux:heading>
                        <p class="text-xs text-neutral-500 mt-1">متاجر باشتراك مدفوع وساري</p>
                    </div>
                </div>

                <!-- الكرت الثالث -->
                <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-zinc-800 p-6 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <flux:subheading class="text-amber-600 dark:text-amber-400">الفترة التجريبية</flux:subheading>
                        <flux:badge color="amber" size="sm">Trial</flux:badge>
                    </div>
                    <div class="mt-4">
                        <flux:heading size="2xl" class="font-bold text-amber-600 dark:text-amber-400">{{ $stats['trial_tenants'] }}</flux:heading>
                        <p class="text-xs text-neutral-500 mt-1">إجمالي المستخدمين بالنظام: {{ $stats['total_users'] }}</p>
                    </div>
                </div>

            </div>

            <!-- الحاوية السفلية: جدول المتاجر -->
            <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-zinc-800 shadow-sm flex flex-col">
                <div class="p-4 border-b border-neutral-200 dark:border-neutral-700 flex justify-between items-center bg-neutral-50/50 dark:bg-zinc-900/50">
                    <div>
                        <flux:heading size="lg">أحدث المتاجر المشتركة</flux:heading>
                        <flux:subheading>متابعة أحدث المتاجر المسجلة في المنصة وإدارتها</flux:subheading>
                    </div>
                    <flux:button variant="primary" size="sm" icon="plus">
                        إضافة متجر
                    </flux:button>
                </div>

                <div class="overflow-x-auto flex-1">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-neutral-100/70 dark:bg-zinc-900 text-neutral-500 dark:text-neutral-400 border-b border-neutral-200 dark:border-neutral-700">
                            <tr>
                                <th class="p-4 font-medium">اسم المتجر</th>
                                <th class="p-4 font-medium">الباقة</th>
                                <th class="p-4 font-medium">حالة الاشتراك</th>
                                <th class="p-4 font-medium">تاريخ التسجيل</th>
                                <th class="p-4 font-medium text-left">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @forelse($recentTenants as $tenant)
                                <tr class="hover:bg-neutral-50/50 dark:hover:bg-zinc-700/30 transition">
                                    <td class="p-4 font-semibold text-neutral-800 dark:text-neutral-200">{{ $tenant->name }}</td>
                                    <td class="p-4">
                                        <flux:badge color="indigo" size="sm">{{ ucfirst($tenant->plan ?? 'Basic') }}</flux:badge>
                                    </td>
                                    <td class="p-4">
                                        @if($tenant->status === 'active')
                                            <flux:badge color="emerald" size="sm">نشط</flux:badge>
                                        @elseif($tenant->status === 'trial')
                                            <flux:badge color="amber" size="sm">تجريبي</flux:badge>
                                        @else
                                            <flux:badge color="red" size="sm">موقوف</flux:badge>
                                        @endif
                                    </td>
                                    <td class="p-4 text-neutral-500 dark:text-neutral-400">{{ $tenant->created_at->format('Y-m-d') }}</td>
                                    <td class="p-4 text-left">
                                        <flux:button variant="ghost" size="sm" icon="eye">عرض</flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-8 text-center text-neutral-500 dark:text-neutral-400">
                                        لا توجد متاجر مسجلة حالياً في المنصة.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </flux:main>
</x-layouts::saas>
