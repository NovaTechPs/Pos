<div class="flex items-start gap-8 max-md:flex-col">
    <aside class="w-full shrink-0 rounded-2xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 md:w-[250px]">
        <p class="px-3 pb-2 pt-1 text-xs font-black uppercase tracking-wide text-zinc-400">الحساب / Account</p>
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate icon="user-circle">
                الملف الشخصي / Profile
            </flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" :current="request()->routeIs('security.edit')" wire:navigate icon="shield-check">
                الأمان وكلمة المرور / Security
            </flux:navlist.item>
        </flux:navlist>

        <p class="px-3 pb-2 pt-5 text-xs font-black uppercase tracking-wide text-zinc-400">النظام / System</p>
        <flux:navlist>
            <flux:navlist.item :href="route('appearance.edit')" :current="request()->routeIs('appearance.edit')" wire:navigate icon="swatch">
                المظهر / Appearance
            </flux:navlist.item>
        </flux:navlist>

        @if (Auth::user()?->hasPermission('store.settings.manage'))
            <p class="px-3 pb-2 pt-5 text-xs font-black uppercase tracking-wide text-zinc-400">المتجر / Store</p>
            <flux:navlist>
                <flux:navlist.item :href="route('store.settings')" :current="request()->routeIs('store.settings')" wire:navigate icon="building-storefront">
                    إعدادات المتجر / Store settings
                </flux:navlist.item>
            </flux:navlist>
        @endif
    </aside>

    <flux:separator class="md:hidden" />

    <div class="min-w-0 flex-1 self-stretch max-md:pt-1">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
