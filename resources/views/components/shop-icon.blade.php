@props(['name' => 'store'])
@php
    $paths = [
        'store' => 'M3 10 12 3l9 7M5 9v11h14V9M9 20v-7h6v7',
        'user' => 'M20 21v-2a7 7 0 0 0-14 0v2M17 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'cart' => 'M2 3h3l3 13h11l3-9H6M10 21h.01M18 21h.01',
        'search' => 'm21 21-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
        'grid' => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
        'arrow' => 'm14 5-7 7 7 7M7 12h14',
        'check' => 'm5 12 4 4L19 6',
        'box' => 'm3 7 9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
        'phone' => 'M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 3.1 5.2 2 2 0 0 1 5.1 3h3l2 5-2 2a16 16 0 0 0 6 6l2-2z',
        'lock' => 'M5 10h14v11H5zM8 10V6a4 4 0 0 1 8 0v4M12 14v3',
        'close' => 'm6 6 12 12M6 18 18 6',
        'clock' => 'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0M12 6v6l4 2',
        'eye' => 'M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
    ];
@endphp
<svg {{ $attributes->class(['shop-icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['store'] }}" /></svg>
