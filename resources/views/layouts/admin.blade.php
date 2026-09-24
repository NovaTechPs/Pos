<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('layouts.partials.head')
</head>

<body class="min-h-screen bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">

    {{-- Desktop Sidebar --}}
    @include('layouts.admin.sidebar')


    {{-- Mobile Header --}}
    <flux:header class="lg:hidden border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        <flux:sidebar.toggle
            class="lg:hidden"
            icon="bars-2"
            inset="left"
        />

        <flux:spacer />

        <flux:dropdown position="top" align="end">

            <flux:profile
                :initials="auth()->user()->initials()"
                icon-trailing="chevron-down"
            />

            <flux:menu>

                {{-- User Information --}}
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start">

                            <flux:avatar
                                :name="auth()->user()->name"
                                :initials="auth()->user()->initials()"
                            />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">
                                    {{ auth()->user()->name }}
                                </flux:heading>

                                <flux:text class="truncate">
                                    {{ auth()->user()->email }}
                                </flux:text>
                            </div>

                        </div>
                    </div>
                </flux:menu.radio.group>


                <flux:menu.separator />


                {{-- Settings --}}
                <flux:menu.item
                    :href="route('profile.edit')"
                    icon="cog"
                    wire:navigate
                >
                    {{ __('Settings') }}
                </flux:menu.item>


                <flux:menu.separator />


                {{-- Logout --}}
                <form
                    method="POST"
                    action="{{ route('logout') }}"
                    class="w-full"
                >
                    @csrf

                    <flux:menu.item
                        as="button"
                        type="submit"
                        icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer"
                        data-test="logout-button"
                    >
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>

            </flux:menu>

        </flux:dropdown>

    </flux:header>


    {{-- Main Content --}}
    <main class="min-h-[calc(100vh-4rem)]">
        {{ $slot }}
    </main>



    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist


    @fluxScripts

</body>
</html>
