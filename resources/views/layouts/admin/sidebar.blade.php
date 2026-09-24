<flux:sidebar
    sticky
    collapsible="mobile"
    class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
>

    {{-- Header --}}
    <flux:sidebar.header>

        <x-app-logo
            :sidebar="true"
            href="{{ route('admin.dashboard') }}"
            wire:navigate
        />

        <flux:sidebar.collapse class="lg:hidden" />

    </flux:sidebar.header>


    {{-- Navigation --}}
    <flux:sidebar.nav>
        @include('layouts.admin.navigation')
    </flux:sidebar.nav>


    {{-- Push Footer Down --}}
    <flux:spacer />


    {{-- User --}}
    <x-desktop-user-menu
        class="hidden lg:block"
        :name="auth()->user()->name"
    />

</flux:sidebar>
