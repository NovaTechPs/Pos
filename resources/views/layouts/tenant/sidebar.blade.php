<flux:sidebar
    sticky
    collapsible
    data-tenant-navigation-drawer
    class="w-72 gap-3 border-e border-slate-200 bg-gradient-to-b from-slate-50 via-white to-blue-50/70 p-3 shadow-[0_0_28px_rgba(15,23,42,0.05)] dark:border-zinc-700 dark:from-zinc-950 dark:via-zinc-900 dark:to-slate-950"
>
    <flux:sidebar.header class="rounded-2xl border border-slate-200 bg-white px-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <x-app-logo
            :sidebar="true"
            href="{{ route('tenant.dashboard') }}"
            wire:navigate
        />

        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />
    </flux:sidebar.header>

    {{-- Store and branch identity --}}
    <div data-tenant-navigation-context>
        <livewire:tenant-switcher />
        <livewire:stock-alerts />
    </div>

    {{-- Main Navigation --}}
    <flux:sidebar.nav aria-label="التنقل الرئيسي">
        @include('layouts.tenant.navigation')
    </flux:sidebar.nav>

    <flux:spacer />

    {{-- Sidebar Footer --}}
    <div data-tenant-navigation-footer class="border-t border-slate-200 px-2 pt-3 dark:border-zinc-700">
        <livewire:layouts::partials.language-switcher />
    </div>

    <div data-tenant-navigation-account>
        <x-desktop-user-menu
            class="hidden lg:block"
            :name="auth()->user()->name"
        />
    </div>
</flux:sidebar>

