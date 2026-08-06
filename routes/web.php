<?php

use Illuminate\Support\Facades\Route;






Route::view('/', 'welcome')->name('home');







Route::middleware(['auth', 'verified', 'is_admin'])->prefix('admin')->name('admin.')->group(function () {

    // صفحة الداشبورد الخاصة بالأدمن
Route::view('/dashboard', 'pages.admin.dashboard')->name('dashboard');
Route::livewire('/plan', 'pages::admin.plans')->name('plan');
Route::livewire('/user', 'pages::admin.users')->name('user');





});
Route::middleware(['auth', 'verified'])->group(function () {

    // صفحة الداشبورد الخاصة بالمستخدم/المتجر

Route::view('/dashboard', 'pages.tenant.dashboard')->name('dashboard');
Route::livewire('dashboard/user', 'pages::tenant.branch')->name('branch');
Route::livewire('dashboard/role', 'pages::tenant.role')->name('role');
Route::livewire('dashboard/employees', 'pages::tenant.employees')->name('employees');
Route::livewire('dashboard/product', 'pages::tenant.product')->name('product');
Route::livewire('dashboard/pos', 'pages::tenant.pos')->name('pos');

    Route::livewire('/post/create', 'pages::post.create');



Route::livewire('dashboard/purchases', 'pages::tenant.purchase')->name('purchases');
Route::livewire('dashboard/wholesale', 'pages::tenant.wholesale')->name('wholesale');
// Route::livewire('dashboard/purchases', 'pages::tenant.')->name('purchases');
// Route::livewire('dashboard/customers', 'pages::tenant.customers')->name('customers');
// Route::livewire('dashboard/suppliers', 'pages::tenant.suppliers')->name('suppliers');






    // أضف بقية مسارات المتجر هنا مستقبلاً (مثل نقاط البيع والمنتجات)
    // Volt::route('/pos', 'tenant.pos.index')->name('pos.index');
});

require __DIR__.'/settings.php';
