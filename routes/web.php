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
        Route::livewire('/supplier', 'pages::tenant.supplier')->name('supplier');

        // المبيعات ونقطة البيع (POS)
        Route::livewire('/pos', 'pages::tenant.pos')->name('pos');
        Route::livewire('/product', 'pages::tenant.product')->name('product');
        Route::livewire('/purchases', 'pages::tenant.purchase')->name('purchases');
        Route::livewire('/wholesale', 'pages::tenant.wholesale')->name('wholesale');
        Route::livewire('/wholesale-interface', 'pages::tenant.wholesale-interface')->name('wholesale-interface');
        Route::livewire('/wholesale-orders', 'pages::tenant.wholesale-orders')->name('wholesale-orders');

        // المتجر الإلكتروني والطلبات
        Route::livewire('/store/{slug}', 'pages::tenant.store')->name('store');
        Route::livewire('/online-orders', 'pages::tenant.online-orders')->name('online-orders');

        // الحسابات والمالية والتقارير
        Route::livewire('/analytics', 'pages::tenant.analytics')->name('analytics');
        Route::livewire('/daily-settlement', 'pages::tenant.daily-settlement-component')->name('daily-settlement');
        Route::livewire('/receipt-vouchers', 'pages::tenant.receipt-vouchers')->name('receipt');
        Route::livewire('/payment', 'pages::tenant.payment')->name('payment');
        Route::livewire('/excel', 'pages::tenant.exsel')->name('excel');

        // أدوات وطباعة
        Route::livewire('/print', 'pages::tenant.print')->name('print');
        Route::livewire('/backup', 'pages::tenant.backup')->name('backup');
    });

// مسارات أخرى
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/post/create', 'pages::post.create')->name('post.create');
});

require __DIR__.'/settings.php';
