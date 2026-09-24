<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}"
>
<head>
    @include('layouts.partials.head')
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100">

    @include('layouts.tenant.sidebar')

    {{-- Mobile Header سنفصله في الخطوة التالية --}}

    {{ $slot }}

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
</body>
</html>
