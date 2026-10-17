<flux:sidebar.group :heading="__('Platform')" class="grid">
    @php
        $navigationItems = [
            ['permission' => 'dashboard.view', 'route' => 'tenant.dashboard', 'label' => 'Dashboard', 'icon' => 'squares-2x2'],
            ['permission' => 'branches.view', 'route' => 'tenant.branch', 'label' => 'Branches', 'icon' => 'building-office-2'],
            ['permission' => 'roles.view', 'route' => 'tenant.role', 'label' => 'Roles', 'icon' => 'shield-check'],
            ['permission' => 'employees.view', 'route' => 'tenant.employees', 'label' => 'Employees', 'icon' => 'user-group'],
            ['permission' => 'customers.view', 'route' => 'tenant.customer', 'label' => 'Customers', 'icon' => 'users'],
            ['permission' => 'categories.view', 'route' => 'tenant.category', 'label' => 'Categories', 'icon' => 'tag'],
            ['permission' => 'products.view', 'route' => 'tenant.product', 'label' => 'Products', 'icon' => 'cube'],
            ['permission' => 'products.barcode.manage', 'route' => 'tenant.barcode.printer', 'label' => 'Barcode Printer', 'icon' => 'printer'],
            ['permission' => 'pos.view', 'route' => 'tenant.pos', 'label' => 'POS', 'icon' => 'calculator'],
            ['permission' => 'sales_invoices.view', 'route' => 'tenant.sale-invoices', 'label' => 'Sales Invoices', 'icon' => 'document-text'],
            ['permission' => 'wholesale_sales.view', 'route' => 'tenant.wholesale-sales', 'label' => 'Wholesale Sales', 'icon' => 'building-office'],
            ['permission' => 'van_sales.view', 'route' => 'tenant.wholesale-invoices', 'label' => 'Van Sales', 'icon' => 'truck'],
            ['permission' => 'stock_transfers.view', 'route' => 'tenant.tenant.stock-transfers', 'label' => 'Stock Transfers', 'icon' => 'arrows-right-left'],
            ['permission' => 'purchases.view', 'route' => 'tenant.purchase-invoices', 'label' => 'Purchase Invoices', 'icon' => 'arrow-down-tray'],
            ['permission' => 'returns.view', 'route' => 'tenant.returns', 'label' => 'Returns', 'icon' => 'arrow-uturn-left'],
            ['permission' => 'store.view', 'route' => 'tenant.store', 'label' => 'Store', 'icon' => 'building-storefront'],
            ['permission' => 'store.orders.view', 'route' => 'tenant.online-orders', 'label' => 'Online Orders', 'icon' => 'shopping-cart'],
            ['permission' => 'reports.analytics', 'route' => 'tenant.analytics', 'label' => 'التقارير / Reports', 'icon' => 'chart-bar-square'],
            ['permission' => 'reports.daily_settlement', 'route' => 'tenant.daily-settlement', 'label' => 'Daily Settlement', 'icon' => 'scale'],
            ['permission' => 'accounting.voucher.view', 'route' => 'tenant.payment', 'label' => 'Vouchers', 'icon' => 'banknotes'],
            ['permission' => 'currency_rates.manage', 'route' => 'tenant.currency-rates', 'label' => 'أسعار الصرف / Exchange Rates', 'icon' => 'currency-dollar'],
            ['permission' => 'accounting.receipt.view', 'route' => 'tenant.receipts', 'label' => 'Receipts', 'icon' => 'receipt-percent'],
            ['permission' => 'excel.manage', 'route' => 'tenant.excel', 'label' => 'Excel Import Export', 'icon' => 'document-arrow-up'],
            ['permission' => 'backup.view', 'route' => 'tenant.backup', 'label' => 'Backup', 'icon' => 'server-stack'],
            ['permission' => 'logs.view', 'route' => 'tenant.logs', 'label' => 'Activity Log', 'icon' => 'clipboard-document-list'],
            ['permission' => 'payroll.view', 'route' => 'tenant.payroll-manager', 'label' => 'Payroll', 'icon' => 'banknotes'],
        ];
    @endphp

    @foreach ($navigationItems as $item)
        @can($item['permission'])
            @if ($item['route'] === 'tenant.store')
                @php
                    $activeBranch = \App\Models\Branch::query()
                        ->where('id', session('active_branch_id'))
                        ->where('tenant_id', session('active_tenant_id'))
                        ->first();
                @endphp

                @if ($activeBranch?->domain)
                    <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'], ['slug' => $activeBranch->domain])"
                        :current="request()->routeIs($item['route'])" wire:navigate>
                        {{ __($item['label']) }}
                    </flux:sidebar.item>
                @endif
            @else
                <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])"
                    :current="request()->routeIs($item['route'])" wire:navigate>
                    {{ __($item['label']) }}
                </flux:sidebar.item>
            @endif
        @endcan
    @endforeach
</flux:sidebar.group>
