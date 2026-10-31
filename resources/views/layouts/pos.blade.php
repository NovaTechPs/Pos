<!DOCTYPE html>
<html dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" data-theme="{{ $appTheme }}">

<head>
    @include('layouts.partials.head')
</head>

<body data-workspace-context="{{ session('active_tenant_id') }}:{{ session('active_branch_id') }}" class="{{ (request()->header('Sec-Fetch-Dest') === 'iframe') ? 'workspace-frame-body' : '' }} app-responsive pos-shell min-h-screen bg-zinc-100 dark:bg-zinc-900 antialiased">

    {{-- POS Header --}}
    @if (request()->header('Sec-Fetch-Dest') !== 'iframe')
    <flux:header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <div class="flex w-full items-center justify-between">

            {{-- Brand --}}
            <div class="flex items-center">

                <x-app-logo href="{{ route('tenant.dashboard') }}" wire:navigate class="h-8" />

                <div class="hidden border-s border-zinc-200 ps-4 dark:border-zinc-700 md:block">
                    <flux:heading size="sm">
                        {{ __('Point of Sale') }}
                    </flux:heading>
                </div>

                <div class="hidden border-s border-zinc-200 ps-4 dark:border-zinc-700 md:block">
                    <flux:sidebar.item icon="home" :href="route('tenant.dashboard')"
                        :current="request()->routeIs('tenant.dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </div>

            </div>

            {{-- User Menu --}}
            <flux:dropdown position="top" align="end">

                <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down" />

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

                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer">
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>

                </flux:menu>

            </flux:dropdown>

        </div>
    </flux:header>
    @endif

    {{-- POS Content --}}
    <main class="flex min-h-0 flex-1 flex-col overflow-hidden">

        <x-workspace-tabs :title="$pageTitle ?? null">
        {{ $slot }}
    </x-workspace-tabs>
    </main>

    {{-- Toasts --}}
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts

</body>

</html>
