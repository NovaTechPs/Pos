@props(['branch' => null, 'title' => null])
@php
    $shopBranch = $branch ?? request()->attributes->get('shop_branch');
    $shopService = app(\App\Services\OnlineShop::class);
    $branding = $shopBranch ? $shopService->branding($shopBranch) : null;
    $shopCustomer = $shopBranch ? $shopService->customer($shopBranch) : null;
    $retailCustomer = $shopBranch ? $shopService->storeCustomer($shopBranch) : null;
    $storeName = $branding['name'] ?? config('app.name');
    $shopHome = $shopBranch ? route('shop.home', ['shop' => $shopBranch->domain]) : '#';
    $accountUrl = $shopBranch ? route($retailCustomer ? 'shop.retail.account' : ($shopCustomer ? 'shop.account' : 'shop.login'), ['shop' => $shopBranch->domain]) : '#';
@endphp
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex">
    <title>{{ $title ? $title.' - '.$storeName : $storeName }}</title>
    <link rel="icon" href="{{ $branding['logo'] ?? asset('favicon.ico') }}">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="storefront" data-shop>
    @if($shopBranch)
        <div class="shop-topbar"><div class="shop-width shop-topbar-inner">
            <span><x-shop-icon name="store" /> أهلاً بك في {{ $storeName }}</span>
            @if($branding['phone'])<a href="tel:{{ preg_replace('/[^+0-9]/', '', $branding['phone']) }}"><x-shop-icon name="phone" /> <b dir="ltr">{{ $branding['phone'] }}</b><span class="shop-desktop-text"> · تواصل مع المتجر</span></a>@else<span><x-shop-icon name="box" /> طلبك يصل مباشرة إلى المحل</span>@endif
        </div></div>
        <header class="shop-header">
            <div class="shop-width shop-header-main">
                <a class="shop-brand" href="{{ $shopHome }}" wire:navigate>
                    @if($branding['logo'])<img class="shop-logo" src="{{ $branding['logo'] }}" alt="شعار {{ $storeName }}">@else<span class="shop-brand-mark"><x-shop-icon name="store" /></span>@endif
                    <span><strong>{{ $storeName }}</strong><small>{{ $branding['tagline'] }}</small></span>
                </a>
                <form action="{{ $shopHome }}" method="GET" class="shop-header-search" role="search">
                    <input name="search" type="search" value="{{ request('search') }}" aria-label="ابحث في المتجر" placeholder="ابحث عن منتج يناسبك…">
                    <button type="submit" aria-label="بحث"><x-shop-icon name="search" /></button>
                </form>
                <div class="shop-header-actions">
                    <a href="{{ $accountUrl }}" wire:navigate data-shop-account-link><x-shop-icon name="user" /><span>{{ $shopCustomer ? 'حساب الجملة' : 'حسابي' }}</span></a>
                    <a href="{{ $shopHome }}#shop-cart" wire:navigate><x-shop-icon name="cart" /><span>السلة</span></a>
                    @if($retailCustomer || $shopCustomer)
                        <form method="POST" action="{{ route($retailCustomer ? 'shop.retail.logout' : 'shop.logout', ['shop' => $shopBranch->domain]) }}">@csrf<button type="submit" class="shop-logout">خروج</button></form>
                    @endif
                </div>
            </div>
            <nav class="shop-width shop-nav" aria-label="تنقل المتجر">
                <a href="{{ $shopHome }}" wire:navigate class="shop-nav-home"><x-shop-icon name="store" /> الرئيسية</a>
                <a href="{{ $shopHome }}#shop-products" wire:navigate>تسوّق المنتجات</a>
                <a href="{{ $accountUrl }}" wire:navigate>طلباتي</a>
                <a href="{{ $shopHome }}#shop-categories" wire:navigate class="shop-nav-categories"><x-shop-icon name="grid" /> جميع الأقسام</a>
            </nav>
        </header>
    @endif
    <main class="shop-width shop-main">{{ $slot }}</main>
    <footer class="shop-footer"><div class="shop-width"><strong>{{ $storeName }}</strong><span>{{ $branding['tagline'] ?? '' }}</span><a href="{{ $accountUrl }}" wire:navigate>حسابي وطلباتي</a></div></footer>
    @livewireScripts
</body>
</html>
