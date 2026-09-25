    <flux:sidebar.group
    :heading="__('Platform')"
    class="grid"
>

    {{-- Dashboard --}}
    @can('dashboard.view')
        <flux:sidebar.item
            icon="home"
            :href="route('tenant.dashboard')"
            :current="request()->routeIs('tenant.dashboard')"
            wire:navigate
        >
            {{ __('Dashboard') }}
        </flux:sidebar.item>
    @endcan


    {{-- Branches --}}
    @can('branches.view')
        <flux:sidebar.item
            icon="building-storefront"
            :href="route('tenant.branch')"
            :current="request()->routeIs('tenant.branch')"
            wire:navigate
        >
            {{ __('Branches') }}
        </flux:sidebar.item>
    @endcan


    {{-- Roles --}}
    @can('roles.view')
        <flux:sidebar.item
            icon="shield-check"
            :href="route('tenant.role')"
            :current="request()->routeIs('tenant.role')"
            wire:navigate
        >
            {{ __('Roles') }}
        </flux:sidebar.item>
    @endcan


    {{-- Employees --}}
    @can('employees.view')
        <flux:sidebar.item
            icon="user-group"
            :href="route('tenant.employees')"
            :current="request()->routeIs('tenant.employees')"
            wire:navigate
        >
            {{ __('Employees') }}
        </flux:sidebar.item>
    @endcan


    {{-- Products --}}
    @can('products.view')
        <flux:sidebar.item
            icon="cube"
            :href="route('tenant.product')"
            :current="request()->routeIs('tenant.product')"
            wire:navigate
        >
            {{ __('Products') }}
        </flux:sidebar.item>
    @endcan


    {{-- POS --}}
    @can('pos.view')
        <flux:sidebar.item
            icon="calculator"
            :href="route('tenant.pos')"
            :current="request()->routeIs('tenant.pos')"
            wire:navigate
        >
            {{ __('POS') }}
        </flux:sidebar.item>
    @endcan


    {{-- Analytics --}}
    @can('analytics.view')
        <flux:sidebar.item
            icon="chart-bar-square"
            :href="route('tenant.analytics')"
            :current="request()->routeIs('tenant.analytics')"
            wire:navigate
        >
            {{ __('Analytics') }}
        </flux:sidebar.item>
    @endcan


    {{-- Daily Settlement --}}
    @can('daily_settlement.view')
        <flux:sidebar.item
            icon="scale"
            :href="route('tenant.daily-settlement')"
            :current="request()->routeIs('tenant.daily-settlement')"
            wire:navigate
        >
            {{ __('Daily Settlement') }}
        </flux:sidebar.item>
    @endcan


    {{-- Online Orders --}}
    @can('store_order.view')
        <flux:sidebar.item
            icon="clipboard-document-list"
            :href="route('tenant.online-orders')"
            :current="request()->routeIs('tenant.online-orders')"
            wire:navigate
        >
            {{ __('Online Orders') }}
        </flux:sidebar.item>
    @endcan


    {{-- Store --}}
    @can('store.view')
        <flux:sidebar.item
            icon="shopping-bag"
            :href="route('tenant.store', [
                'slug' => session('active_tenant_slug') ?? 'default',
            ])"
            :current="request()->routeIs('tenant.store')"
            wire:navigate
        >
            {{ __('Store') }}
        </flux:sidebar.item>
    @endcan


    {{-- Wholesale Sales --}}
    @can('wholesale_sales.view')
        <flux:sidebar.item
            icon="building-office-2"
            :href="route('tenant.wholesale-sales')"
            :current="request()->routeIs('tenant.wholesale-sales')"
            wire:navigate
        >
            {{ __('Wholesale Sales') }}
        </flux:sidebar.item>
    @endcan


    {{-- Van Sales --}}
    @can('van_sales.view')
        <flux:sidebar.item
            icon="truck"
            :href="route('tenant.wholesale-invoices')"
            :current="request()->routeIs('tenant.wholesale-invoices')"
            wire:navigate
        >
            {{ __('Van Sales') }}
        </flux:sidebar.item>
    @endcan


    {{-- Sales Invoices --}}
    @can('sales_invoices.view')
        <flux:sidebar.item
            icon="document-text"
            :href="route('tenant.sale-invoices')"
            :current="request()->routeIs('tenant.sale-invoices')"
            wire:navigate
        >
            {{ __('Sales Invoices') }}
        </flux:sidebar.item>
    @endcan


    {{-- Purchase Invoices --}}
    @can('purchase_invoices.view')
        <flux:sidebar.item
            icon="arrow-down-tray"
            :href="route('tenant.purchase-invoices')"
            :current="request()->routeIs('tenant.purchase-invoices')"
            wire:navigate
        >
            {{ __('Purchase Invoices') }}
        </flux:sidebar.item>
    @endcan


    {{-- Customers --}}
    @can('customers.view')
        <flux:sidebar.item
            icon="users"
            :href="route('tenant.customer')"
            :current="request()->routeIs('tenant.customer')"
            wire:navigate
        >
            {{ __('Customers') }}
        </flux:sidebar.item>
    @endcan


    {{-- Vouchers --}}
    @can('vouchers.view')
        <flux:sidebar.item
            icon="credit-card"
            :href="route('tenant.payment')"
            :current="request()->routeIs('tenant.payment')"
            wire:navigate
        >
            {{ __('Vouchers') }}
        </flux:sidebar.item>
    @endcan
       @can('receipts.view')
        <flux:sidebar.item
            icon="building-storefront"
            :href="route('tenant.receipts')"
            :current="request()->routeIs('tenant.receipts')"
            wire:navigate
        >
            {{ __('receipts') }}
        </flux:sidebar.item>
    @endcan


    {{-- Backup --}}
    @can('backup.view')
        <flux:sidebar.item
            icon="server-stack"
            :href="route('tenant.backup')"
            :current="request()->routeIs('tenant.backup')"
            wire:navigate
        >
            {{ __('Backup') }}
        </flux:sidebar.item>
    @endcan

</flux:sidebar.group>
