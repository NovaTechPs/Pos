<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@php
    $appName = config('app.name', 'WaslaPOS');
    $activeRouteName = request()->route()?->getName();
    $routeTitles = [
        'admin.dashboard' => ['ar' => 'لوحة الإدارة', 'en' => 'Admin Dashboard'],
        'admin.plan' => ['ar' => 'الخطط', 'en' => 'Plans'],
        'admin.user' => ['ar' => 'المستخدمون', 'en' => 'Users'],
        'appearance.edit' => ['ar' => 'المظهر', 'en' => 'Appearance'],
        'login' => ['ar' => 'تسجيل الدخول', 'en' => 'Login'],
        'password.request' => ['ar' => 'استعادة كلمة المرور', 'en' => 'Reset Password'],
        'profile.edit' => ['ar' => 'الملف الشخصي', 'en' => 'Profile'],
        'register' => ['ar' => 'إنشاء حساب', 'en' => 'Register'],
        'security.edit' => ['ar' => 'الأمان', 'en' => 'Security'],
        'store.settings' => ['ar' => 'إعدادات المتجر', 'en' => 'Store Settings'],
        'tenant.analytics' => ['ar' => 'التحليلات', 'en' => 'Analytics'],
        'tenant.backup' => ['ar' => 'النسخ الاحتياطي', 'en' => 'Backups'],
        'tenant.barcode.printer' => ['ar' => 'طباعة الباركود', 'en' => 'Barcode Printing'],
        'tenant.branch' => ['ar' => 'الفروع', 'en' => 'Branches'],
        'tenant.category' => ['ar' => 'التصنيفات', 'en' => 'Categories'],
        'tenant.customer' => ['ar' => 'الزبائن', 'en' => 'Customers'],
        'tenant.currency-rates' => ['ar' => 'أسعار الصرف', 'en' => 'Exchange Rates'],
        'tenant.daily-settlement' => ['ar' => 'التسوية اليومية', 'en' => 'Daily Settlement'],
        'tenant.dashboard' => ['ar' => 'لوحة التحكم', 'en' => 'Dashboard'],
        'tenant.employees' => ['ar' => 'الموظفون', 'en' => 'Employees'],
        'tenant.excel' => ['ar' => 'إدارة ملفات Excel', 'en' => 'Excel Tools'],
        'tenant.logs' => ['ar' => 'سجل النشاط', 'en' => 'Activity Log'],
        'tenant.online-orders' => ['ar' => 'الطلبات الإلكترونية', 'en' => 'Online Orders'],
        'tenant.payment' => ['ar' => 'المدفوعات', 'en' => 'Payments'],
        'tenant.payroll-manager' => ['ar' => 'مسير الرواتب', 'en' => 'Payroll'],
        'tenant.pos' => ['ar' => 'نقطة البيع', 'en' => 'Point of Sale'],
        'tenant.product' => ['ar' => 'المنتجات', 'en' => 'Products'],
        'tenant.returns' => ['ar' => 'المردودات', 'en' => 'Returns'],
        'tenant.sales-returns' => ['ar' => 'مردود المبيعات', 'en' => 'Sales Returns'],
        'tenant.purchase-returns' => ['ar' => 'مردود المشتريات', 'en' => 'Purchase Returns'],
        'tenant.purchase-invoices' => ['ar' => 'فواتير المشتريات', 'en' => 'Purchase Invoices'],
        'tenant.receipts' => ['ar' => 'السندات المالية', 'en' => 'Financial Vouchers'],
        'tenant.role' => ['ar' => 'الأدوار والصلاحيات', 'en' => 'Roles and Permissions'],
        'tenant.sale-invoices' => ['ar' => 'فواتير المبيعات', 'en' => 'Sales Invoices'],
        'tenant.store' => ['ar' => 'المتجر', 'en' => 'Store'],
        'tenant.tenant.stock-transfers' => ['ar' => 'تحويلات المخزون', 'en' => 'Stock Transfers'],
        'tenant.wholesale-invoices' => ['ar' => 'فواتير الجملة', 'en' => 'Wholesale Invoices'],
        'tenant.wholesale-sales' => ['ar' => 'مبيعات الجملة', 'en' => 'Wholesale Sales'],
    ];
    $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
    $fallbackTitle = filled($activeRouteName)
        ? \Illuminate\Support\Str::headline(str_replace('.', ' ', preg_replace('/^(tenant|admin)\\./', '', $activeRouteName)))
        : $appName;
    $pageTitle = $routeTitles[$activeRouteName][$locale] ?? $title ?? $fallbackTitle;
@endphp

<title>{{ $pageTitle === $appName ? $appName : $pageTitle.' - '.$appName }}</title>

<link rel="icon" href="{{ asset('favicon.ico') }}?v=waslapos-1" sizes="any">
<link rel="icon" href="{{ asset('favicon.svg') }}?v=waslapos-1" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=waslapos-1">

@include('layouts.partials.pwa')

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
