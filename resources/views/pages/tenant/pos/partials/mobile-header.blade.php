{{-- ترويسة نقطة البيع على الجوال: الشعار والأيقونات وأزرار الأقسام (تظهر تحت 1024px فقط) --}}
<div data-pos-mobile-header class="pos-mobile-header-bar" dir="rtl"
    x-data="{
        toggleSections() {
            let shell = document;
            try {
                if (window.top !== window && window.top.document.querySelector('[data-workspace-menu]')) shell = window.top.document;
            } catch (error) {}
            const menu = shell.querySelector('[data-workspace-menu]');
            if (!menu) {
                window.location.assign(@js(route('tenant.dashboard')));
                return;
            }
            menu.open = !menu.open;
            if (menu.open) menu.querySelector('summary')?.focus({ preventScroll: true });
        },
        focusSearch() {
            const input = $root.closest('[data-pos-page]')?.querySelector('[data-pos-barcode-input]');
            input?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            input?.focus({ preventScroll: true });
        },
    }">
    <div class="pos-mobile-appbar" dir="ltr">
        <button type="button" x-on:click="toggleSections()" aria-label="فتح قائمة الأقسام" class="pos-mobile-icon-button">
            <flux:icon.bars-3 class="size-6" />
        </button>

        <a href="{{ route('tenant.dashboard') }}" class="pos-mobile-brand" aria-label="WaslaPOS">
            <img src="{{ asset('wasla-mark.svg') }}" class="size-8" alt="">
            <span>WaslaPOS</span>
        </a>

        <div class="pos-mobile-appbar-actions">
            <button type="button" x-on:click="focusSearch()" aria-label="البحث عن صنف" class="pos-mobile-icon-button pos-mobile-icon-round">
                <flux:icon.magnifying-glass class="size-5" />
            </button>

            <button type="button" data-pos-mobile-held wire:click="$set('showHeldModal', true)"
                aria-label="الفواتير المعلقة{{ count($heldInvoices) ? ' ('.count($heldInvoices).')' : '' }}"
                class="pos-mobile-icon-button pos-mobile-icon-round relative">
                <flux:icon.bell class="size-5" />
                @if (count($heldInvoices))
                    <span class="pos-mobile-dot" aria-hidden="true"></span>
                @endif
            </button>

            <flux:dropdown position="bottom" align="end">
                <button type="button" aria-label="حساب المستخدم" class="pos-mobile-icon-button pos-mobile-icon-round">
                    <flux:icon.user class="size-5" />
                </button>

                <flux:menu>
                    <div class="px-3 py-2 text-sm">
                        <div class="font-bold text-zinc-800 dark:text-white">{{ auth()->user()->name }}</div>
                        <div class="text-xs text-zinc-500">{{ auth()->user()->email }}</div>
                    </div>
                    <flux:menu.separator />
                    <flux:menu.item :href="route('profile.edit')" icon="cog">{{ __('Settings') }}</flux:menu.item>
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">{{ __('Log out') }}</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <nav data-pos-mobile-section-pills class="pos-mobile-pills" aria-label="التنقل السريع">
        <button type="button" x-on:click="toggleSections()" class="pos-mobile-pill">
            <span>الأقسام</span>
            <flux:icon.square-3-stack-3d class="size-5" />
        </button>
        <a href="{{ route('tenant.dashboard') }}" class="pos-mobile-pill">
            <span>لوحة التحكم</span>
            <flux:icon.squares-2x2 class="size-5" />
        </a>
        <span class="pos-mobile-pill pos-mobile-pill-active" aria-current="page">
            <span>نقطة البيع</span>
            <flux:icon.shopping-cart class="size-5" />
        </span>
    </nav>
</div>
