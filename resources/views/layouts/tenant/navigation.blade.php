<flux:sidebar.group :heading="__('Platform')" class="grid">

    {{-- Dashboard --}}
    @can('dashboard.view')
        <flux:sidebar.item icon="squares-2x2" :href="route('tenant.dashboard')"
            :current="request()->routeIs('tenant.dashboard')" target="_blank">
            {{ __('Dashboard') }}
        </flux:sidebar.item>
    @endcan


    {{-- Branches --}}
    @can('branches.view')
        <flux:sidebar.item icon="building-office-2" :href="route('tenant.branch')"
            :current="request()->routeIs('tenant.branch')" target="_blank">
            {{ __('Branches') }}
        </flux:sidebar.item>
    @endcan


    {{-- Categories --}}
    @can('category.view')
        <flux:sidebar.item icon="tag" :href="route('tenant.category')" :current="request()->routeIs('tenant.category')"
            target="_blank">
            {{ __('Category') }}
        </flux:sidebar.item>
    @endcan


    {{-- Roles --}}
    @can('roles.view')
        <flux:sidebar.item icon="shield-check" :href="route('tenant.role')" :current="request()->routeIs('tenant.role')"
            target="_blank">
            {{ __('Roles') }}
        </flux:sidebar.item>
    @endcan


    {{-- Employees --}}
    @can('employees.view')
        <flux:sidebar.item icon="user-group" :href="route('tenant.employees')"
            :current="request()->routeIs('tenant.employees')" target="_blank">
            {{ __('Employees') }}
        </flux:sidebar.item>
    @endcan


    {{-- Products --}}
    @can('products.view')
        <flux:sidebar.item icon="cube" :href="route('tenant.product')" :current="request()->routeIs('tenant.product')"
            target="_blank">
            {{ __('Products') }}
        </flux:sidebar.item>
    @endcan


    {{-- POS --}}
    @can('pos.view')
        <flux:sidebar.item icon="calculator" :href="route('tenant.pos')" :current="request()->routeIs('tenant.pos')"
            target="_blank">
            {{ __('POS') }}
        </flux:sidebar.item>
    @endcan


    {{-- Analytics --}}
    @can('analytics.view')
        <flux:sidebar.item icon="chart-bar-square" :href="route('tenant.analytics')"
            :current="request()->routeIs('tenant.analytics')" target="_blank">
            {{ __('Analytics') }}
        </flux:sidebar.item>
    @endcan


    {{-- Daily Settlement --}}
    @can('daily_settlement.view')
        <flux:sidebar.item icon="scale" :href="route('tenant.daily-settlement')"
            :current="request()->routeIs('tenant.daily-settlement')" target="_blank">
            {{ __('Daily Settlement') }}
        </flux:sidebar.item>
    @endcan


    {{-- Online Orders --}}
    @can('store_order.view')
        <flux:sidebar.item icon="shopping-cart" :href="route('tenant.online-orders')"
            :current="request()->routeIs('tenant.online-orders')" target="_blank">
            {{ __('Online Orders') }}
        </flux:sidebar.item>
    @endcan


    {{-- Store --}}
    {{-- Store --}}

    {{-- Store --}}

    @can('store.view')

        @php
            $activeBranchId = session('active_branch_id');

            $activeBranch = \App\Models\Branch::query()
                ->where('id', $activeBranchId)
                ->where('tenant_id', session('active_tenant_id'))
                ->first();
        @endphp

        @if ($activeBranch?->domain)
            <flux:sidebar.item icon="building-storefront" :href="route('store', ['slug' => $activeBranch->domain])"
                :current="request()->routeIs('store')" target="_blank">
                {{ __('Store') }}
            </flux:sidebar.item>
        @endif

    @endcan

    {{-- Wholesale Sales --}}
    @can('wholesale_sales.view')
        <flux:sidebar.item icon="building-office" :href="route('tenant.wholesale-sales')"
            :current="request()->routeIs('tenant.wholesale-sales')" target="_blank">
            {{ __('Wholesale Sales') }}
        </flux:sidebar.item>
    @endcan


    {{-- Van Sales --}}
    @can('van_sales.view')
        <flux:sidebar.item icon="truck" :href="route('tenant.wholesale-invoices')"
            :current="request()->routeIs('tenant.wholesale-invoices')" target="_blank">
            {{ __('Van Sales') }}
        </flux:sidebar.item>
    @endcan


    {{-- Sales Invoices --}}
    @can('sales_invoices.view')
        <flux:sidebar.item icon="document-text" :href="route('tenant.sale-invoices')"
            :current="request()->routeIs('tenant.sale-invoices')" target="_blank">
            {{ __('Sales Invoices') }}
        </flux:sidebar.item>
    @endcan


    {{-- Purchase Invoices --}}
    @can('purchase_invoices.view')
        <flux:sidebar.item icon="arrow-down-tray" :href="route('tenant.purchase-invoices')"
            :current="request()->routeIs('tenant.purchase-invoices')" target="_blank">
            {{ __('Purchase Invoices') }}
        </flux:sidebar.item>
    @endcan


    {{-- Customers --}}
    @can('customers.view')
        <flux:sidebar.item icon="users" :href="route('tenant.customer')"
            :current="request()->routeIs('tenant.customer')" target="_blank">
            {{ __('Customers') }}
        </flux:sidebar.item>
    @endcan


    {{-- Vouchers --}}
    @can('vouchers.view')
        <flux:sidebar.item icon="banknotes" :href="route('tenant.payment')"
            :current="request()->routeIs('tenant.payment')" target="_blank">
            {{ __('Vouchers') }}
        </flux:sidebar.item>
    @endcan


    {{-- Receipts --}}
    @can('receipts.view')
        <flux:sidebar.item icon="receipt-percent" :href="route('tenant.receipts')"
            :current="request()->routeIs('tenant.receipts')" target="_blank">
            {{ __('Receipts') }}
        </flux:sidebar.item>
    @endcan


    {{-- Backup --}}
    @can('backup.view')
        <flux:sidebar.item icon="server-stack" :href="route('tenant.backup')"
            :current="request()->routeIs('tenant.backup')" target="_blank">
            {{ __('Backup') }}
        </flux:sidebar.item>
    @endcan


    {{-- Barcode Printer --}}
    @can('barcode.print')
        <flux:sidebar.item icon="printer" :href="route('tenant.barcode.printer')"
            :current="request()->routeIs('tenant.barcode.printer')" target="_blank">
            {{ __('Barcode Printer') }}
        </flux:sidebar.item>
    @endcan
       @can('logs.view')
        <flux:sidebar.item icon="building-office" :href="route('tenant.logs')"
            :current="request()->routeIs('tenant.logs')" target="_blank">
            {{ __('logs') }}
        </flux:sidebar.item>
    @endcan

</flux:sidebar.group>
