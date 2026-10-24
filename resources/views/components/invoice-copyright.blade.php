@props(['pdf' => false])

@php
    $copyright = 'جميع الحقوق محفوظة © '.now()->year.' — WaslaPOS';
    if ($pdf) {
        $copyright = (new \ArPHP\I18N\Arabic)->utf8Glyphs($copyright, 150, true, true);
    }
@endphp

<div data-invoice-copyright dir="rtl" style="margin-top: 8px; padding-top: 4px; text-align: center; font-size: 9px; font-weight: 400; line-height: 1.5; color: #333; overflow-wrap: anywhere; break-inside: avoid; page-break-inside: avoid;">
    {{ $copyright }}
</div>
