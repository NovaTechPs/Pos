<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">

<head>
    @include('partials.head')

    <!-- حماية ثبات الوضع الليلي عند التحديث وتنقلات Livewire -->
    <script>
        function applyTheme() {
            const theme = localStorage.getItem('theme');
            if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        }

        // تطبيق الوضع عند تحميل الصفحة لأول مرة
        applyTheme();

        // إعادة تطبيق الوضع فوراً بعد كل تنقل عبر wire:navigate أو تحديث Livewire
        document.addEventListener('livewire:navigated', applyTheme);
    </script>
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100">
    <flux:sidebar sticky collapsible="mobile"
        class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <livewire:pages::tenant.tenant-switcher />

        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('Platform')" class="grid">

                @can('dashboard.view')
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                @endcan

                @can('branches.view')
                    <flux:sidebar.item icon="building-storefront" :href="route('branch')" :current="request()->routeIs('branch')" wire:navigate>
                        {{ __('Branches') }}
                    </flux:sidebar.item>
                @endcan

                @can('roles.view')
                    <flux:sidebar.item icon="shield-check" :href="route('role')" :current="request()->routeIs('role')" wire:navigate>
                        {{ __('Roles') }}
                    </flux:sidebar.item>
                @endcan

                @can('employees.view')
                    <flux:sidebar.item icon="user-group" :href="route('employees')" :current="request()->routeIs('employees')" wire:navigate>
                        {{ __('Employees') }}
                    </flux:sidebar.item>
                @endcan

                @can('products.view')
                    <flux:sidebar.item icon="cube" :href="route('product')" :current="request()->routeIs('product')" wire:navigate>
                        {{ __('Products') }}
                    </flux:sidebar.item>
                @endcan

                @can('pos.view')
                    <flux:sidebar.item icon="calculator" :href="route('pos')" :current="request()->routeIs('pos')" wire:navigate>
                        {{ __('POS') }}
                    </flux:sidebar.item>
                @endcan

                @can('analytics.view')
                    <flux:sidebar.item icon="chart-bar-square" :href="route('analytics')" :current="request()->routeIs('analytics')" wire:navigate>
                        {{ __('Analytics') }}
                    </flux:sidebar.item>
                @endcan

                @can('daily_settlement.view')
                    <flux:sidebar.item icon="scale" :href="route('DailySettlementComponent')" :current="request()->routeIs('DailySettlementComponent')" wire:navigate>
                        {{ __('Daily Settlement') }}
                    </flux:sidebar.item>
                @endcan

                @can('store_order.view')
                    <flux:sidebar.item icon="clipboard-document-list" :href="route('storeORDEE')" :current="request()->routeIs('storeORDEE')" wire:navigate>
                        {{ __('Store Order') }}
                    </flux:sidebar.item>
                @endcan

                @can('store.view')
                    <flux:sidebar.item icon="shopping-bag" :href="route('store', ['slug' => session('active_tenant_slug') ?? 'default'])" :current="request()->routeIs('store')" wire:navigate>
                        {{ __('Store') }}
                    </flux:sidebar.item>
                @endcan

                @can('wholesale_sales.view')
                    <flux:sidebar.item icon="building-office-2" :href="route('wholesaleinterface')" :current="request()->routeIs('wholesaleinterface')" wire:navigate>
                        {{ __('Wholesale Sales') }}
                    </flux:sidebar.item>
                @endcan

                @can('van_sales.view')
                    <flux:sidebar.item icon="truck" :href="route('wholesaleorders')" :current="request()->routeIs('wholesaleorders')" wire:navigate>
                        {{ __('Van Sales') }}
                    </flux:sidebar.item>
                @endcan

                @can('sales_invoices.view')
                    <flux:sidebar.item icon="document-text" :href="route('wholesale')" :current="request()->routeIs('wholesale')" wire:navigate>
                        {{ __('Sales Invoices') }}
                    </flux:sidebar.item>
                @endcan

                @can('purchase_invoices.view')
                    <flux:sidebar.item icon="arrow-down-tray" :href="route('purchases')" :current="request()->routeIs('purchases')" wire:navigate>
                        {{ __('Purchase Invoices') }}
                    </flux:sidebar.item>
                @endcan

                @can('customers.view')
                    <flux:sidebar.item icon="users" :href="route('customer')" :current="request()->routeIs('customer')" wire:navigate>
                        {{ __('Customers') }}
                    </flux:sidebar.item>
                @endcan

                @can('suppliers.view')
                    <flux:sidebar.item icon="truck" :href="route('supplier')" :current="request()->routeIs('supplier')" wire:navigate>
                        {{ __('Suppliers') }}
                    </flux:sidebar.item>
                @endcan

                @can('vouchers.view')
                    <flux:sidebar.item icon="credit-card" :href="route('payment')" :current="request()->routeIs('payment')" wire:navigate>
                        {{ __('Vouchers') }}
                    </flux:sidebar.item>
                @endcan

            </flux:sidebar.group>
        </flux:sidebar.nav>

        <flux:spacer />

        <!-- أدوات التحكم باللغة والمظهر (Desktop Sidebar) -->
        <div class="px-2 py-3 space-y-2 border-t border-zinc-200 dark:border-zinc-700">
            <!-- تبديل اللغة باستخدام المكون Single File Component -->
            <livewire:layouts::language-switcher />

            <!-- تبديل الثيم الداكن / الفاتح -->
            <div x-data="{
                darkMode: localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches),
                toggle() {
                    this.darkMode = !this.darkMode;
                    if (this.darkMode) {
                        document.documentElement.classList.add('dark');
                        localStorage.setItem('theme', 'dark');
                    } else {
                        document.documentElement.classList.remove('dark');
                        localStorage.setItem('theme', 'light');
                    }
                }
            }">
                <flux:button @click="toggle()" variant="subtle" class="w-full justify-start">
                    <template x-if="darkMode">
                        <div class="flex items-center gap-2">
                            <flux:icon name="sun" class="size-4" />
                            <span>{{ __('Light Mode') }}</span>
                        </div>
                    </template>
                    <template x-if="!darkMode">
                        <div class="flex items-center gap-2">
                            <flux:icon name="moon" class="size-4" />
                            <span>{{ __('Dark Mode') }}</span>
                        </div>
                    </template>
                </flux:button>
            </div>
        </div>

        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile User Menu & Header -->
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>
