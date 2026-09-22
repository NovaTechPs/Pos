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
        // الصفحة الرئيسية للمتجر
        Route::view('/', 'pages.tenant.dashboard')->name('dashboard');

        // إدارة النظام والعملاء والبروفايل
        Route::livewire('/user', 'pages::tenant.branch')->name('branch');
        Route::livewire('/role', 'pages::tenant.role')->name('role');
        Route::livewire('/employees', 'pages::tenant.employees')->name('employees');
        Route::livewire('/customer', 'pages::tenant.customer')->name('customer');

        // المبيعات ونقطة البيع (POS)
        Route::livewire('/pos', 'pages::tenant.pos')->name('pos');
        Route::livewire('/product', 'pages::tenant.product')->name('product');
        Route::livewire('/purchase-invoices', 'pages::tenant.purchase-invoice')->name('purchase-invoices');
        Route::livewire('/sale-invoices', 'pages::tenant.sale-invoice')->name('sale-invoices');
        Route::livewire('/wholesale-sales', 'pages::tenant.wholesale-sales')->name('wholesale-sales');
        Route::livewire('/wholesale-invoices', 'pages::tenant.wholesale-invoices')->name('wholesale-invoices');

        // المتجر الإلكتروني والطلبات
        Route::livewire('/store/{slug}', 'pages::tenant.store')->name('store');
        Route::livewire('/online-orders', 'pages::tenant.online-orders')->name('online-orders');

        // الحسابات والمالية والتقارير
        Route::livewire('/analytics', 'pages::tenant.analytics')->name('analytics');
        Route::livewire('/daily-settlement', 'pages::tenant.daily-settlement-component')->name('daily-settlement');
        Route::livewire('/payment', 'pages::tenant.payment')->name('payment');
        Route::livewire('/excel', 'pages::tenant.excel')->name('excel');

        // أدوات وطباعة
        Route::livewire('/backup', 'pages::tenant.backup')->name('backup');
    });


require __DIR__ . '/settings.php';
