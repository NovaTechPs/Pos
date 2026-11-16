<div
    data-tenant-navigation-layer
    x-cloak
    x-show="tenantNavigationOpen"
    x-transition.opacity
    x-on:click.self="tenantNavigationOpen = false"
    class="fixed inset-0 z-[100] bg-slate-950/35 p-2 backdrop-blur-[1px]"
>
    <flux:sidebar
        data-tenant-navigation-drawer
        role="dialog"
        aria-modal="true"
        aria-label="القائمة الرئيسية"
        x-on:click="if ($event.target.closest('a[data-flux-sidebar-item]')) tenantNavigationOpen = false"
        class="fixed inset-y-2 end-2 h-[calc(100dvh-1rem)] w-[min(18rem,calc(100vw-1rem))] gap-3 overflow-y-auto rounded-[1.5rem] border border-slate-200 bg-gradient-to-b from-slate-50 via-white to-blue-50/70 p-3 shadow-2xl dark:border-zinc-700 dark:from-zinc-950 dark:via-zinc-900 dark:to-slate-950"
    >
        <flux:sidebar.header class="flex-none rounded-2xl border border-slate-200 bg-white px-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <x-app-logo
                :sidebar="true"
                href="{{ route('tenant.dashboard') }}"
                wire:navigate
            />

            <flux:button
                variant="subtle"
                square
                icon="x-mark"
                x-on:click="tenantNavigationOpen = false"
                aria-label="إغلاق القائمة الرئيسية"
            />
        </flux:sidebar.header>

        {{-- Store and branch identity --}}
        <livewire:tenant-switcher />
        <livewire:stock-alerts />

        {{-- Main Navigation --}}
        <flux:sidebar.nav class="flex-none" aria-label="التنقل الرئيسي">
            @include('layouts.tenant.navigation')
        </flux:sidebar.nav>

        <flux:spacer />

        {{-- Sidebar Footer --}}
        <div class="border-t border-slate-200 px-2 pt-3 dark:border-zinc-700">
            <livewire:layouts::partials.language-switcher />
        </div>

        <x-desktop-user-menu
            class="hidden lg:block"
            :name="auth()->user()->name"
        />
    </flux:sidebar>
</div>

