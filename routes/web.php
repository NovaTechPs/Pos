<?php

use App\Http\Controllers\TenantDashboardController;
use Illuminate\Support\Facades\Route;

// الصفحة الرئيسية
Route::view('/', 'welcome')->name('home');

// --------------------------------------------------------------------------
// مسارات لوحة تحكم الأدمن (Admin Routes)
// --------------------------------------------------------------------------
Route::middleware(['auth', 'verified', 'is_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::view('/dashboard', 'pages.admin.dashboard')->name('dashboard');
        Route::livewire('/plan', 'pages::admin.plans')->name('plan');
        Route::livewire('/user', 'pages::admin.users')->name('user');
    });

// --------------------------------------------------------------------------
// مسارات المستخدم / المتجر (Tenant Routes)
// --------------------------------------------------------------------------
Route::middleware(['auth', 'verified'])
    ->prefix('dashboard')
    ->name('tenant.')
    ->group(function () {

        // Dashboard
        Route::get('/', TenantDashboardController::class)
            ->name('dashboard');

        // Branches / Employees / Roles / Customers
        Route::livewire('/branch', 'pages::tenant.branches.index')
            ->middleware('can:branches.view')
            ->name('branch');

        Route::livewire('/role', 'pages::tenant.roles.index')
            ->middleware('can:roles.view')
            ->name('role');

        Route::livewire('/employees', 'pages::tenant.employees.index')
            ->middleware('can:employees.view')
            ->name('employees');

        Route::livewire('/customer', 'pages::tenant.customers.index')
            ->middleware('can:customers.view')
            ->name('customer');

        // Categories / Products
        Route::livewire('/stock-movements', 'pages::tenant.stock-movements.index')
            ->middleware('can:products.view')->name('stock-movements');
        Route::livewire('/category', 'pages::tenant.categories.index')
            ->middleware('can:categories.view')
            ->name('category');

        Route::livewire('/product', 'pages::tenant.products.index')
            ->middleware('can:products.view')
            ->name('product');

        Route::livewire('/barcode/printer', 'pages::tenant.products.printer')
            ->middleware('can:products.barcode.manage')
            ->name('barcode.printer');

        // POS
        Route::livewire('/pos', 'pages::tenant.pos.sales')
            ->middleware('can:pos.view')
            ->name('pos');

        // Sales
        Route::livewire('/sale-invoices', 'pages::tenant.sales.invoices')
            ->middleware('can:sales_invoices.view')
            ->name('sale-invoices');

        // Wholesale
        Route::livewire('/wholesale-sales', 'pages::tenant.wholesale.sales')
            ->middleware('can:wholesale_sales.view')
            ->name('wholesale-sales');

        Route::livewire('/wholesale-invoices', 'pages::tenant.wholesale.invoices')
            ->middleware('can:van_sales.view')
            ->name('wholesale-invoices');

        Route::livewire('/stock-transfers', 'pages::tenant.stock-transfers.index')
            ->middleware('can:stock_transfers.view')
            ->name('tenant.stock-transfers');
        // Purchases
        Route::livewire('/purchase-invoices', 'pages::tenant.purchases.invoices')
            ->middleware('can:purchases.view')
            ->name('purchase-invoices');

        Route::livewire('/returns', 'pages::tenant.returns.index')
            ->middleware('can:returns.view')
            ->name('returns');

        Route::livewire('/sales-returns', 'pages::tenant.returns.index')
            ->middleware('can:returns.view')
            ->name('sales-returns');

        Route::livewire('/purchase-returns', 'pages::tenant.returns.index')
            ->middleware('can:returns.view')
            ->name('purchase-returns');

        // Online Store
        Route::livewire('/store/{slug}', 'pages::tenant.stores.index')
            ->middleware('can:store.view')
            ->name('store');

        Route::livewire('/online-orders', 'pages::tenant.stores.online')
            ->middleware('can:store.orders.view')
            ->name('online-orders');

        // Reports
        Route::livewire('/analytics', 'pages::tenant.reports.analytics')
            ->middleware('can:reports.analytics')
            ->name('analytics');

        // Accounting
        Route::livewire('/daily-settlement', 'pages::tenant.accounting.daily-settlement')
            ->middleware('can:reports.daily_settlement')
            ->name('daily-settlement');
        Route::livewire('/payment', 'pages::tenant.accounting.payment')
            ->middleware('can:accounting.voucher.view')
            ->name('payment');

        Route::livewire('/exchange-rates', 'pages::tenant.accounting.exchange-rates')
            ->middleware('can:currency_rates.manage')
            ->name('currency-rates');
        Route::livewire('/receipts', 'pages::tenant.accounting.receipts')
            ->middleware('can:accounting.receipt.view')
            ->name('receipts');

        // System
        Route::livewire('/excel', 'pages::tenant.system.excel')
            ->middleware('can:excel.manage')
            ->name('excel');

        Route::livewire('/backup', 'pages::tenant.system.backup')
            ->middleware(['is_admin', 'can:backup.view'])
            ->name('backup');

        Route::livewire('/logs', 'pages::tenant.system.logs')
            ->middleware('can:logs.view')
            ->name('logs');
        Route::livewire('/payroll-manager', 'pages::tenant.hr.payroll-manager')
            ->middleware('can:payroll.view')
            ->name('payroll-manager');
    });
require __DIR__.'/settings.php';
