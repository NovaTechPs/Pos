@props(['branch' => null, 'title' => null])
@php
    $shopBranch = $branch ?? request()->attributes->get('shop_branch');
    $shopCustomer = $shopBranch ? app(\App\Services\OnlineShop::class)->customer($shopBranch) : null;
    $storeName = $shopBranch?->name ?: config('app.name');
@endphp
<!DOCTYPE html>
<html dir="rtl" lang="ar">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex" />
        <title>{{ $title ? $title.' - '.$storeName : $storeName }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased" data-shop>
        @if ($shopBranch)
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
                    <a href="{{ route('shop.home', ['shop' => $shopBranch->domain]) }}" wire:navigate class="truncate text-lg font-black text-teal-700">{{ $storeName }}</a>
                    <nav class="flex items-center gap-2 text-sm font-bold">
                        <a href="{{ route('shop.home', ['shop' => $shopBranch->domain]) }}" wire:navigate class="rounded-lg px-3 py-2 hover:bg-slate-100">المتجر</a>
                        @if ($shopCustomer)
                            <a href="{{ route('shop.account', ['shop' => $shopBranch->domain]) }}" wire:navigate class="rounded-lg px-3 py-2 hover:bg-slate-100" data-shop-account-link>حسابي</a>
                            <form method="POST" action="{{ route('shop.logout', ['shop' => $shopBranch->domain]) }}">
                                @csrf
                                <button type="submit" class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100">خروج</button>
                            </form>
                        @else
                            <a href="{{ route('shop.login', ['shop' => $shopBranch->domain]) }}" wire:navigate class="rounded-lg bg-teal-600 px-3 py-2 text-white hover:bg-teal-500">دخول</a>
                        @endif
                    </nav>
                </div>
            </header>
        @endif

        <main class="mx-auto max-w-6xl px-4 py-5">
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
