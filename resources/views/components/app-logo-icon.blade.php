<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 250 250" {{ $attributes->merge(['role' => 'img', 'aria-label' => 'WaslaPOS']) }}>
    <defs>
        <linearGradient id="waslaLogoTile" x1="36" y1="30" x2="215" y2="222" gradientUnits="userSpaceOnUse">
            <stop stop-color="#2864E8" />
            <stop offset="1" stop-color="#123272" />
        </linearGradient>
        <linearGradient id="waslaLogoLink" x1="72" y1="87" x2="185" y2="166" gradientUnits="userSpaceOnUse">
            <stop stop-color="#75F0D5" />
            <stop offset="1" stop-color="#20C6A6" />
        </linearGradient>
    </defs>
    <rect x="18" y="18" width="214" height="214" rx="62" fill="url(#waslaLogoTile)" />
    <path d="M111 84H91a33 33 0 0 0 0 66h31m17 16h20a33 33 0 0 0 0-66h-31" stroke="white" stroke-width="19" stroke-linecap="round" />
    <path d="m105 145 40-40" stroke="url(#waslaLogoLink)" stroke-width="20" stroke-linecap="round" />
    <circle cx="105" cy="145" r="9" fill="#75F0D5" />
    <circle cx="145" cy="105" r="9" fill="#75F0D5" />
</svg>
