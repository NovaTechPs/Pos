<?php

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
        Route::view('/', 'pages.tenant.dashboard')
            ->name('dashboard');

        // Branches / Employees / Roles / Customers
        Route::livewire('/branch', 'pages::tenant.branches.index')
            ->name('branch');

        Route::livewire('/role', 'pages::tenant.roles.index')
            ->name('role');

        Route::livewire('/employees', 'pages::tenant.employees.index')
            ->name('employees');

        Route::livewire('/customer', 'pages::tenant.customers.index')
            ->name('customer');

        // Categories / Products
        Route::livewire('/category', 'pages::tenant.categories.index')
            ->name('category');

        Route::livewire('/product', 'pages::tenant.products.index')
            ->name('product');

        // POS
        Route::livewire('/pos', 'pages::tenant.pos.sales')
            ->name('pos');

        // Sales
        Route::livewire('/sale-invoices', 'pages::tenant.sales.invoices')
            ->name('sale-invoices');

        // Wholesale
        Route::livewire('/wholesale-sales', 'pages::tenant.wholesale.sales')
            ->name('wholesale-sales');

        Route::livewire('/wholesale-invoices', 'pages::tenant.wholesale.invoices')
            ->name('wholesale-invoices');

        // Purchases
        Route::livewire('/purchase-invoices', 'pages::tenant.purchases.invoices')
            ->name('purchase-invoices');

        // Online Store
        Route::livewire('/store/{slug}', 'pages::tenant.stores.index')
            ->name('store');

        Route::livewire('/online-orders', 'pages::tenant.orders.online')
            ->name('online-orders');

        // Reports
        Route::livewire('/analytics', 'pages::tenant.reports.analytics')
            ->name('analytics');

        // Accounting
        Route::livewire('/daily-settlement', 'pages::tenant.accounting.daily-settlement')
            ->name('daily-settlement');
        Route::livewire('/payment', 'pages::tenant.accounting.payment')
            ->name('payment');

        // System
        Route::livewire('/excel', 'pages::tenant.system.excel')
            ->name('excel');

        Route::livewire('/backup', 'pages::tenant.system.backup')
            ->name('backup');
    });
require __DIR__ . '/settings.php';
