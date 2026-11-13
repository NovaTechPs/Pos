@props(['hint' => 'وجّه الكاميرا إلى الباركود؛ سيُضاف الصنف تلقائيًا بعد قراءته.'])

{{--
    Camera preview for posBarcodeCamera(); place it inside the element that holds its x-data.
    A native modal dialog opens in the browser's top layer, so it shows above other open
    dialogs such as the product form, where a z-index overlay would stay hidden behind them.
--}}
<dialog
    data-barcode-camera-dialog
    x-effect="if (open && !$el.open) $el.showModal(); else if (!open && $el.open) $el.close();"
    x-on:cancel.prevent="close()"
    x-on:click.stop="if ($event.target === $el) close()"
    aria-label="قراءة الباركود بالكاميرا"
    dir="rtl"
    class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl bg-white p-4 text-start shadow-xl backdrop:bg-slate-950/70"
>
    <div class="mb-3 flex items-center justify-between gap-3">
        <h2 class="font-bold text-slate-900">قراءة الباركود بالكاميرا</h2>
        <button type="button" x-on:click="close()" aria-label="إغلاق الكاميرا" class="h-10 w-10 rounded-lg bg-slate-100 text-slate-700">✕</button>
    </div>
    <video x-ref="preview" wire:ignore autoplay muted playsinline class="aspect-video w-full rounded-xl bg-slate-950 object-cover"></video>
    <p x-show="loading" role="status" class="mt-3 text-sm text-indigo-600">جارٍ تشغيل الكاميرا...</p>
    <p x-show="!error" class="mt-3 text-sm text-slate-600">{{ $hint }}</p>
    <p x-show="error" x-text="error" role="alert" class="mt-3 text-sm text-rose-600"></p>
    <button x-show="error" type="button" x-on:click="start()" class="mt-3 rounded-xl bg-indigo-600 px-4 py-2 text-sm text-white">إعادة المحاولة</button>
</dialog>
