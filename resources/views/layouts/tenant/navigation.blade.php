@php
    $activeBranch = \App\Models\Branch::query()
        ->select(['id', 'domain'])
        ->where('id', session('active_branch_id'))
        ->where('tenant_id', session('active_tenant_id'))
        ->first();

    $navigationGroups = [
        [
            'label' => 'Home',
            'icon' => 'squares-2x2',
            'items' => [
                ['permission' => 'dashboard.view', 'route' => 'tenant.dashboard', 'label' => 'Dashboard', 'icon' => 'squares-2x2'],
            ],
        ],
        [
            'label' => 'People and Access',
            'icon' => 'shield-check',
            'items' => [
                ['permission' => 'branches.view', 'route' => 'tenant.branch', 'label' => 'Branches', 'icon' => 'building-office-2'],
                ['permission' => 'roles.view', 'route' => 'tenant.role', 'label' => 'Roles', 'icon' => 'shield-check'],
                ['permission' => 'employees.view', 'route' => 'tenant.employees', 'label' => 'Employees', 'icon' => 'user-group'],
            ],
        ],
        [
            'label' => 'Customers and Suppliers',
            'icon' => 'users',
            'items' => [
                ['permission' => 'customers.view', 'route' => 'tenant.customer', 'label' => 'Customers', 'icon' => 'users'],
                ['permission' => 'store.orders.view', 'route' => 'tenant.customer-orders', 'label' => 'طلبات المتجر الإلكتروني', 'icon' => 'shopping-bag'],
                ['permission' => 'customer_visits.manage', 'route' => 'tenant.customer-visits', 'label' => 'زيارات المندوبين', 'icon' => 'map'],
            ],
        ],
        [
            'label' => 'Inventory and Products',
            'icon' => 'cube',
            'items' => [
                ['permission' => 'categories.view', 'route' => 'tenant.category', 'label' => 'Categories', 'icon' => 'tag'],
                ['permission' => 'products.view', 'route' => 'tenant.product', 'label' => 'Products', 'icon' => 'cube'],
                ['permission' => 'products.view', 'route' => 'tenant.offers', 'label' => 'Offers', 'icon' => 'tag'],
                ['permission' => 'products.barcode.manage', 'route' => 'tenant.barcode.printer', 'label' => 'Barcode Printer', 'icon' => 'printer'],
                ['permission' => 'products.view', 'route' => 'tenant.stock-movements', 'label' => 'Stock Movements', 'icon' => 'arrows-right-left'],
                ['permission' => 'products.stock.adjust', 'route' => 'tenant.stock-vouchers', 'label' => 'Stock In / Out Vouchers', 'icon' => 'document-plus'],
                ['permission' => 'products.stock.adjust', 'route' => 'tenant.stock-counts', 'label' => 'الجرد بالجوال', 'icon' => 'clipboard-document-check'],
                ['permission' => 'stock_transfers.view', 'route' => 'tenant.tenant.stock-transfers', 'label' => 'Stock Transfers', 'icon' => 'arrows-right-left'],
            ],
        ],
        [
            'label' => 'Invoices',
            'icon' => 'document-text',
            'items' => [
                ['permission' => 'pos.view', 'route' => 'tenant.pos', 'label' => 'POS', 'icon' => 'calculator'],
                ['permission' => 'sales_invoices.view', 'route' => 'tenant.sale-invoices', 'label' => 'Sales Invoices', 'icon' => 'document-text'],
                ['permission' => 'returns.view', 'route' => 'tenant.sales-returns', 'label' => 'Sales Returns', 'icon' => 'arrow-uturn-left'],
                ['permission' => 'wholesale_sales.view', 'route' => 'tenant.wholesale-sales', 'label' => 'Wholesale Sales', 'icon' => 'building-office'],
                ['permission' => 'purchases.view', 'route' => 'tenant.purchase-invoices', 'label' => 'Purchase Invoices', 'icon' => 'arrow-down-tray'],
                ['permission' => 'returns.view', 'route' => 'tenant.purchase-returns', 'label' => 'Purchase Returns', 'icon' => 'arrow-uturn-left'],
                ['permission' => 'store.orders.view', 'route' => 'tenant.online-orders', 'label' => 'Online Orders', 'icon' => 'shopping-cart'],
            ],
        ],
        [
            'label' => 'Reports and Accounts',
            'icon' => 'chart-bar-square',
            'items' => [
                ['permission' => 'reports.analytics', 'route' => 'tenant.reports', 'label' => 'التقارير / Reports', 'icon' => 'chart-bar-square'],
                ['permission' => 'reports.daily_settlement', 'route' => 'tenant.daily-settlement', 'label' => 'Daily Settlement', 'icon' => 'scale'],
                ['permission' => 'accounting.voucher.view', 'route' => 'tenant.payment', 'label' => 'Vouchers', 'icon' => 'banknotes'],
                ['permission' => 'currency_rates.manage', 'route' => 'tenant.currency-rates', 'label' => 'أسعار الصرف / Exchange Rates', 'icon' => 'currency-dollar'],
                ['permission' => 'accounting.receipt.view', 'route' => 'tenant.receipts', 'label' => 'Receipts', 'icon' => 'receipt-percent'],
                ['permission' => 'payroll.view', 'route' => 'tenant.payroll-manager', 'label' => 'Payroll', 'icon' => 'banknotes'],
                ['permission' => 'tasks.access', 'route' => 'tenant.employee-tasks', 'label' => 'Employee Tasks', 'icon' => 'clipboard-document-check'],
            ],
        ],
        [
            'label' => 'Store and Tools',
            'icon' => 'cog',
            'items' => [
                ['permission' => 'store.view', 'route' => 'tenant.store', 'label' => 'Store', 'icon' => 'building-storefront'],
                ['permission' => 'excel.manage', 'route' => 'tenant.excel', 'label' => 'Excel Import Export', 'icon' => 'document-arrow-up'],
                ['permission' => 'backup.view', 'route' => 'tenant.tenant-backups', 'label' => 'نسخ المتجر واستعادته', 'icon' => 'server-stack'],
                ['permission' => 'backup.view', 'route' => 'tenant.backup', 'label' => 'Backup', 'icon' => 'server-stack'],
                ['permission' => 'logs.view', 'route' => 'tenant.logs', 'label' => 'Activity Log', 'icon' => 'clipboard-document-list'],
            ],
        ],
    ];
@endphp

<div data-tenant-navigation class="space-y-0.5 px-1 pb-3">
    @foreach ($navigationGroups as $group)
        @php
            $visibleItems = collect($group['items'])->filter(function (array $item) use ($activeBranch): bool {
                $user = auth()->user();
                if (! $user?->can($item['permission'])) {
                    return false;
                }

                if ($item['route'] === 'tenant.payment' && ! app(\App\Services\PaymentVoucherAccess::class)->available()) {
                    return false;
                }

                if ($item['route'] === 'tenant.tenant-backups' && ! ($user->isSaaSAdmin() || $user->isTenantOwner())) {
                    return false;
                }

                if ($item['route'] === 'tenant.backup' && ! $user->isSaaSAdmin()) {
                    return false;
                }

                if ($item['route'] === 'tenant.store') {
                    return filled($activeBranch?->domain);
                }

                return true;
            })->values();
            $groupIsCurrent = $visibleItems->contains(fn (array $item): bool => request()->routeIs($item['route']));
        @endphp

        @if ($visibleItems->isNotEmpty())
        <details data-tenant-navigation-group @if ($groupIsCurrent || $loop->first) open @endif class="overflow-hidden border-b border-slate-100 last:border-0 dark:border-zinc-800">
            <summary
                title="{{ __($group['label']) }}"
                x-on:click="if ($el.closest('[data-flux-sidebar-collapsed-desktop]')) $dispatch('flux-sidebar-toggle')"
                class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-blue-50 hover:text-blue-700 [&::-webkit-details-marker]:hidden dark:text-zinc-200 dark:hover:bg-zinc-800 dark:hover:text-white"
            >
                <span class="flex min-w-0 items-center gap-2.5">
                    <flux:icon :name="$group['icon']" class="size-5 shrink-0 text-blue-600 dark:text-blue-400" />
                    <span class="truncate">{{ __($group['label']) }}</span>
                </span>
                <svg data-tenant-navigation-chevron class="h-4 w-4 shrink-0 text-slate-400 transition-transform dark:text-zinc-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.09 1.03l-4.25 4.51a.75.75 0 0 1-1.09 0l-4.25-4.51a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                </svg>
            </summary>

            <div class="grid gap-0.5 px-1 pb-2 pt-1">
                @foreach ($visibleItems as $item)
                    @if ($item['route'] === 'tenant.store')
                        @if ($activeBranch?->domain)
                            <flux:sidebar.item class="rounded-xl" :icon="$item['icon']" :href="route($item['route'], ['slug' => $activeBranch->domain])" :current="request()->routeIs($item['route'])" wire:navigate>
                                {{ __($item['label']) }}
                            </flux:sidebar.item>
                        @endif
                    @else
                        <flux:sidebar.item class="rounded-xl" :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['route'])" wire:navigate>
                            @if ($item['route'] === 'tenant.wholesale-sales' && str_contains((string) request()->userAgent(), 'Android'))
                                {{ __('Sales') }}
                            @else
                                {{ __($item['label']) }}
                            @endif
                        </flux:sidebar.item>
                    @endif
                @endforeach
            </div>
        </details>
        @endif
    @endforeach
</div>
