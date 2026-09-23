<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    @include('layouts.partials.head')
    <title>{{ $title ?? 'نظام البيع السريع - POS' }}</title>
</head>
<body class="bg-gray-900 text-white antialiased overflow-hidden select-none">

    {{ $slot }}

    {{-- تضمين JS التجميعي مرة واحدة في نهاية الصفحة --}}
    @livewireScripts
    @stack('scripts')
</body>
</html>
