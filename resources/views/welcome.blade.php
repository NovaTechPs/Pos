<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WaslaPOS - {{ __('Retail Management System') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=waslapos-1" sizes="any">
    <link rel="icon" href="{{ asset('favicon.svg') }}?v=waslapos-1" type="image/svg+xml">
    @include('layouts.partials.pwa')

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS CDN (Works instantly without running npm build) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="app-responsive bg-slate-900 text-slate-100 antialiased flex flex-col min-h-screen justify-between">

    <!-- Navigation Header -->
    <header class="container mx-auto px-4 py-4 flex flex-wrap justify-between items-center gap-4 sm:px-6 sm:py-6">
        <div class="flex items-center space-x-3">
            <img src="{{ asset('wasla-mark.svg') }}" class="h-10 w-10" alt="">
            <span class="text-2xl font-bold tracking-tight text-white">WaslaPOS</span>
        </div>

        <nav class="flex flex-wrap items-center gap-2 sm:gap-4">
            @if (Route::has('login'))
                @auth
                    <a href="{{ url('/dashboard') }}" class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-medium transition shadow-md shadow-indigo-600/20">
                        {{ __('Go to Dashboard') }}
                    </a>
                @else
                    <a href="{{ route('login') }}" class="px-4 py-2 text-slate-300 hover:text-white font-medium transition">
                        {{ __('Log in') }}
                    </a>

                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-medium transition shadow-md shadow-indigo-600/20">
                            {{ __('Register') }}
                        </a>
                    @endif
                @endauth
            @else
                <!-- Fallback buttons if standard Laravel Auth routes aren't defined yet -->
                <a href="/login" class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-medium transition shadow-md shadow-indigo-600/20">
                    {{ __('Access System') }}
                </a>
            @endif
        </nav>
    </header>

    <!-- Main Hero Section -->
    <main class="container mx-auto px-6 py-12 flex-grow flex flex-col justify-center items-center text-center">

        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-800 border border-slate-700 text-indigo-400 text-sm font-medium mb-8">
            <span class="flex h-2 w-2 rounded-full bg-indigo-400 animate-pulse"></span>
            {{ __('Fast, Reliable & Offline-Ready POS') }}
        </div>

        <h1 class="text-4xl md:text-6xl font-extrabold text-white max-w-4xl tracking-tight leading-tight mb-6">
            {{ __('Smart Checkout & Inventory Management for') }} <span class="text-indigo-400">WaslaPOS</span>
        </h1>

        <p class="text-slate-400 text-lg md:text-xl max-w-2xl mb-10 leading-relaxed">
            {{ __('Manage transactions, print customer receipts, and track stock in real time — all from one clean interface.') }}
        </p>

        <!-- Quick Access Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 w-full sm:w-auto">
            @auth
                <a href="{{ url('/dashboard') }}" class="px-8 py-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-lg transition shadow-xl shadow-indigo-600/25 flex items-center justify-center gap-2">
                    {{ __('Open Terminal') }}
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                </a>
            @else
                <a href="{{ route('login') }}" class="px-8 py-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-lg transition shadow-xl shadow-indigo-600/25 flex items-center justify-center gap-2">
                    {{ __('Start Checkout Terminal') }}
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                </a>
            @endauth
        </div>

        <!-- System Feature Highlights -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-20 w-full max-w-5xl text-start">
            <div class="p-6 bg-slate-800/50 border border-slate-700/60 rounded-2xl">
                <div class="w-12 h-12 bg-indigo-600/10 text-indigo-400 rounded-xl flex items-center justify-center mb-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <h3 class="text-xl font-semibold text-white mb-2">{{ __('Rapid Checkout') }}</h3>
                <p class="text-slate-400 text-sm leading-relaxed">{{ __('Built for quick barcode scanning and instant thermal receipt printing.') }}</p>
            </div>

            <div class="p-6 bg-slate-800/50 border border-slate-700/60 rounded-2xl">
                <div class="w-12 h-12 bg-indigo-600/10 text-indigo-400 rounded-xl flex items-center justify-center mb-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                </div>
                <h3 class="text-xl font-semibold text-white mb-2">{{ __('Inventory Sync') }}</h3>
                <p class="text-slate-400 text-sm leading-relaxed">{{ __('Stock levels update automatically as items are sold across all registers.') }}</p>
            </div>

            <div class="p-6 bg-slate-800/50 border border-slate-700/60 rounded-2xl">
                <div class="w-12 h-12 bg-indigo-600/10 text-indigo-400 rounded-xl flex items-center justify-center mb-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 012-2h2a2 2 0 012 2v6m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                </div>
                <h3 class="text-xl font-semibold text-white mb-2">{{ __('Sales Analytics') }}</h3>
                <p class="text-slate-400 text-sm leading-relaxed">{{ __('Track daily revenue, best-selling products, and cashier performance.') }}</p>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer class="container mx-auto px-6 py-6 text-center text-slate-500 text-sm">
        &copy; {{ date('Y') }} WaslaPOS. {{ __('All rights reserved.') }}
    </footer>

</body>
</html>
