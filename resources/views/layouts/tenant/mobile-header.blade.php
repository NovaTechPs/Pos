<flux:header class="border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur lg:hidden dark:border-zinc-800 dark:bg-zinc-950/95">

    {{-- Mobile navigation drawer toggle --}}
    <flux:button
        variant="subtle"
        square
        icon="bars-3"
        data-tenant-navigation-trigger
        x-on:click="$dispatch('tenant-navigation-toggle')"
        aria-label="فتح القائمة الرئيسية"
        class="rounded-xl lg:hidden"
    />

    <a href="{{ route('tenant.dashboard') }}" class="ms-2 inline-flex items-center gap-2 font-extrabold text-slate-800 dark:text-white" wire:navigate aria-label="WaslaPOS">
        <img src="{{ asset('wasla-mark.svg') }}" class="size-8" alt="">
        <span>WaslaPOS</span>
    </a>


    <flux:spacer />


    {{-- Mobile User Menu --}}
    <flux:dropdown
        position="top"
        align="end"
    >

        <flux:profile
            :initials="auth()->user()->initials()"
            icon-trailing="chevron-down"
        />


        <flux:menu>

            {{-- User Information --}}
            <flux:menu.radio.group>

                <div class="p-0 text-sm font-normal">

                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">

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
