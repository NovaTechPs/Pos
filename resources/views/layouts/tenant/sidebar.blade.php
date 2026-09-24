<flux:sidebar
    sticky
    collapsible="mobile"
    class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
>
    {{-- Header --}}
    <flux:sidebar.header>
        <x-app-logo
            :sidebar="true"
            href="{{ route('tenant.dashboard') }}"
            wire:navigate
        />

        <flux:sidebar.collapse class="lg:hidden" />
    </flux:sidebar.header>


    {{-- Tenant Switcher --}}
    <livewire:pages::tenant.tenant-switcher />


    {{-- Main Navigation --}}
    <flux:sidebar.nav>
        @include('layouts.tenant.navigation')
    </flux:sidebar.nav>


    {{-- Push footer content to bottom --}}
    <flux:spacer />


    {{-- Sidebar Footer --}}
    <div class="border-t border-zinc-200 px-2 py-3 dark:border-zinc-700">
        {{-- Language Switcher --}}
        <livewire:layouts::partials.language-switcher />
    </div>


    {{-- Desktop User Menu --}}
    <x-desktop-user-menu
        class="hidden lg:block"
        :name="auth()->user()->name"
    />
</flux:sidebar>

