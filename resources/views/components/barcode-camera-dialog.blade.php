@props(['hint' => 'وجّه الكاميرا إلى الباركود؛ سيُضاف الصنف تلقائيًا بعد قراءته.'])

{{-- Camera preview for posBarcodeCamera(); place it inside the element that holds its x-data. --}}
<template x-teleport="body">
    <div x-cloak x-show="open" x-on:keydown.escape.window="if (open) { $event.stopPropagation(); close(); }" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-label="قراءة الباركود بالكاميرا" dir="rtl">
        <section x-on:click.outside="close()" class="w-full max-w-lg rounded-2xl bg-white p-4 shadow-xl">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="font-bold text-slate-900">قراءة الباركود بالكاميرا</h2>
                <button type="button" x-on:click="close()" aria-label="إغلاق الكاميرا" class="h-10 w-10 rounded-lg bg-slate-100 text-slate-700">✕</button>
            </div>
            <video x-ref="preview" wire:ignore autoplay muted playsinline class="aspect-video w-full rounded-xl bg-slate-950 object-cover"></video>
            <p x-show="loading" role="status" class="mt-3 text-sm text-indigo-600">جارٍ تشغيل الكاميرا...</p>
            <p x-show="!error" class="mt-3 text-sm text-slate-600">{{ $hint }}</p>
            <p x-show="error" x-text="error" role="alert" class="mt-3 text-sm text-rose-600"></p>
            <button x-show="error" type="button" x-on:click="start()" class="mt-3 rounded-xl bg-indigo-600 px-4 py-2 text-sm text-white">إعادة المحاولة</button>
        </section>
    </div>
</template>
