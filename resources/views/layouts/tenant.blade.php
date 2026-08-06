<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky collapsible="mobile"
        class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('Platform')" class="grid">
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')"
                    wire:navigate>
                    {{ __('Dashboard') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="building-storefront" :href="route('branch')"
                    :current="request()->routeIs('branch')" wire:navigate>
                    {{ __('branch') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="shield-check" :href="route('role')" :current="request()->routeIs('role')"
                    wire:navigate>
                    {{ __('role') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="users" :href="route('employees')" :current="request()->routeIs('employees')"
                    wire:navigate>
                    {{ __('employees') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="shopping-bag" :href="route('product')"
                    :current="request()->routeIs('product')" wire:navigate>
                    {{ __('products') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="calculator" :href="route('pos')" :current="request()->routeIs('pos')"
                    wire:navigate>
                    {{ __('نقطة البيع (POS)') }}
                </flux:sidebar.item>
                <!-- Wholesale -->
                <flux:sidebar.item icon="shopping-bag" :href="route('wholesale')"
                    :current="request()->routeIs('wholesale')" wire:navigate>
                    {{ __('مبيعات الجملة') }}
                </flux:sidebar.item>

                <!-- Purchases -->
                <flux:sidebar.item icon="arrow-down-tray" :href="route('purchases')"
                    :current="request()->routeIs('purchases')" wire:navigate>
                    {{ __('فواتير المشتريات') }}
                </flux:sidebar.item>

                <!-- Customers -->
                {{-- <flux:sidebar.item icon="users" :href="route('customers')"
                    :current="request()->routeIs('customers')" wire:navigate>
                    {{ __('إدارة العملاء') }}
                </flux:sidebar.item>

                <!-- Suppliers -->
                <flux:sidebar.item icon="building-office" :href="route('suppliers')"
                    :current="request()->routeIs('suppliers')" wire:navigate>
                    {{ __('إدارة الموردين') }}
                </flux:sidebar.item> --}}
            </flux:sidebar.group>
        </flux:sidebar.nav>

        <flux:spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit"
                target="_blank">
                {{ __('Repository') }}
            </flux:sidebar.item>

            <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire"
                target="_blank">
                {{ __('Documentation') }}
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile User Menu -->
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
