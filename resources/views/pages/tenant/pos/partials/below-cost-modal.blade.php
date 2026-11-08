@if ($showBelowCostModal)
    <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm" x-data x-trap.inert.noscroll="true">
        <section role="dialog" aria-modal="true" aria-labelledby="below-cost-title" tabindex="-1" x-init="$nextTick(() => $el.querySelector('[data-pos-dialog-action]:not(:disabled)')?.focus({ preventScroll: true }))" x-on:keydown.arrow-down.prevent="const actions = [...$el.querySelectorAll('[data-pos-dialog-action]:not(:disabled)')]; const index = actions.indexOf(document.activeElement); if (index !== -1 && actions.length) actions[(index + 1) % actions.length].focus()" x-on:keydown.arrow-right.prevent="const actions = [...$el.querySelectorAll('[data-pos-dialog-action]:not(:disabled)')]; const index = actions.indexOf(document.activeElement); if (index !== -1 && actions.length) actions[(index + 1) % actions.length].focus()" x-on:keydown.arrow-up.prevent="const actions = [...$el.querySelectorAll('[data-pos-dialog-action]:not(:disabled)')]; const index = actions.indexOf(document.activeElement); if (index !== -1 && actions.length) actions[(index - 1 + actions.length) % actions.length].focus()" x-on:keydown.arrow-left.prevent="const actions = [...$el.querySelectorAll('[data-pos-dialog-action]:not(:disabled)')]; const index = actions.indexOf(document.activeElement); if (index !== -1 && actions.length) actions[(index - 1 + actions.length) % actions.length].focus()" class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="bg-amber-500 p-4 text-white"><div id="below-cost-title" class="text-sm font-black">⚠ تأكيد البيع تحت التكلفة</div><div class="mt-1 text-[10px] font-bold text-amber-100">يوجد صنف واحد أو أكثر بسعر بيع أقل من التكلفة.</div></div>
            <div class="max-h-56 space-y-2 overflow-y-auto p-4">
                @foreach ($cart as $item)
                    @if ($item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0))
                        <div class="flex items-center justify-between rounded-xl bg-amber-50 p-2 text-xs"><span class="font-black">{{ $item['name'] }}</span><span class="font-mono font-black text-rose-700">{{ number_format($item['price'],2) }} / {{ number_format($item['cost_price'],2) }}</span></div>
                    @endif
                @endforeach
            </div>
            <div class="grid grid-cols-2 gap-2 p-4 pt-0"><button type="button" data-pos-dialog-action wire:click="confirmBelowCostCheckout" class="rounded-xl bg-amber-500 px-3 py-3 text-xs font-black text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700">متابعة وحفظ</button><button type="button" data-pos-dialog-action wire:click="$set('showBelowCostModal', false)" class="rounded-xl bg-slate-100 px-3 py-3 text-xs font-black text-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700">إلغاء</button></div>
        </section>
    </div>
@endif
