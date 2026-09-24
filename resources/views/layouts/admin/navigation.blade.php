<flux:sidebar.group
    :heading="__('Platform')"
    class="grid"
>

    {{-- Dashboard --}}
    <flux:sidebar.item
        icon="home"
        :href="route('admin.dashboard')"
        :current="request()->routeIs('admin.dashboard*')"
        wire:navigate
    >
        {{ __('Dashboard') }}
    </flux:sidebar.item>


    {{-- Plans --}}
    <flux:sidebar.item
        icon="credit-card"
        :href="route('admin.plan')"
        :current="request()->routeIs('admin.plan*')"
        wire:navigate
    >
        {{ __('Plans') }}
    </flux:sidebar.item>


    {{-- Users --}}
    <flux:sidebar.item
        icon="users"
        :href="route('admin.user')"
        :current="request()->routeIs('admin.user*')"
        wire:navigate
    >
        {{ __('Users') }}
    </flux:sidebar.item>

</flux:sidebar.group>
