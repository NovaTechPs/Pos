@props(['message' => null, 'variant' => 'success'])

{{-- Shows the message as a short floating flash instead of a banner that pushes the page down. --}}
@if (filled($message))
    <p
        wire:key="flash-notice-{{ $variant }}-{{ md5($message) }}"
        data-flash-message
        role="status"
        class="sr-only"
        x-data
        x-init="$dispatch('app-flash-notice', { message: $el.textContent.trim(), variant: @js($variant) })"
    >{{ $message }}</p>
@endif
