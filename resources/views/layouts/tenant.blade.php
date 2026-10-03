<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}"
>
<head>
    @include('layouts.partials.head')

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <style>
        /*
        |--------------------------------------------------------------------------
        | Mobile Header / Burger
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1023px) {
            .pos-mobile-header {
                display: flex !important;
                position: relative;
                z-index: 10000;
            }
        }

        @media (min-width: 1024px) {
            .pos-mobile-header {
                display: none !important;
            }
        }
    </style>
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100">

    {{-- Sidebar --}}
    @include('layouts.tenant.sidebar')

    {{-- Mobile Header / Burger --}}
    @include('layouts.tenant.mobile-header')

    {{ $slot }}

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts

    <script>
        window.addEventListener('load', function () {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/sw.js').catch(function (error) {
                    console.warn('POS Service Worker registration failed:', error);
                });
            }
        });
    </script>

</body>
</html>
