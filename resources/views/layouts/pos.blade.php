<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('layouts.partials.head')
</head>

<body class="min-h-screen bg-zinc-100 dark:bg-zinc-900 flex flex-col antialiased">

    <!-- Navbar علوي خفيف وشامل للـ POS -->
    <flux:header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950 px-4 py-2 flex items-center justify-between gap-4">

        <!-- اللوجو وروابط سريعة -->
        <div class="flex items-center gap-4">
            <x-app-logo href="{{ route('tenant.dashboard') }}" wire:navigate class="h-8" />

            <flux:navbar class="hidden md:flex">
                <flux:navbar.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard*')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:navbar.item>

                <flux:navbar.item icon="shopping-cart" href="#" current>
                    {{ __('POS') }}
                </flux:navbar.item>
            </flux:navbar>
        </div>

        <!-- القائمة الشخصية والإعدادات السريعة -->
        <div class="flex items-center gap-3">
            <flux:dropdown position="top" align="end">
                <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

                <flux:menu>
                    <div class="px-3 py-2 text-xs text-zinc-500">
                        {{ auth()->user()->email }}
                    </div>
                    <flux:menu.separator />

                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </div>
    </flux:header>

    <!-- منطقة محتوى الـ POS الرئيسي (تأخذ كامل المساحة المتبقية) -->
    <main class="flex-1 p-3 md:p-4 overflow-hidden">
        {{ $slot }}
    </main>

    <!-- إشعارات النظام -->
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>
