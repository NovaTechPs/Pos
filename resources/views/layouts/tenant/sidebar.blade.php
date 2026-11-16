<flux:sidebar
    sticky
    collapsible="mobile"
    data-tenant-navigation-drawer
    class="w-80 gap-3 border-e border-slate-200 bg-gradient-to-b from-slate-50 via-white to-blue-50/70 p-3 shadow-[0_0_28px_rgba(15,23,42,0.05)] dark:border-zinc-700 dark:from-zinc-950 dark:via-zinc-900 dark:to-slate-950"
>
    {{-- Global tenant navigation. On mobile Flux exposes this as the hamburger drawer. --}}
    <flux:sidebar.header class="rounded-2xl border border-white/80 bg-white/90 px-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/90">
        <x-app-logo
            :sidebar="true"
            href="{{ route('tenant.dashboard') }}"
            wire:navigate
        />

        <flux:sidebar.collapse class="lg:hidden" />
    </flux:sidebar.header>


    {{-- Store and branch identity --}}
    <livewire:tenant-switcher />
    <livewire:stock-alerts />

    {{-- Main Navigation --}}
    <flux:sidebar.nav aria-label="التنقل الرئيسي">
        @include('layouts.tenant.navigation')
    </flux:sidebar.nav>


    {{-- Push footer content to bottom --}}
    <flux:spacer />


    {{-- Sidebar Footer --}}
    <div class="border-t border-slate-200 px-2 pt-3 dark:border-zinc-700">
        {{-- Language Switcher --}}
        <livewire:layouts::partials.language-switcher />
    </div>


    {{-- Desktop User Menu --}}
    <x-desktop-user-menu
        class="hidden lg:block"
        :name="auth()->user()->name"
    />
</flux:sidebar>

