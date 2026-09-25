@if ($showBelowCostModal)
    <div class="fixed inset-0 z-[60] bg-slate-950/70 backdrop-blur-sm p-4 flex items-center justify-center">
        <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl overflow-hidden border border-amber-200">
            <div class="p-5 bg-amber-500 text-white flex items-center justify-between"><div><h3 class="font-black">تنبيه سعر أقل من التكلفة</h3><p class="text-[10px] text-amber-100 mt-1">تحتاج هذه الفاتورة إلى تأكيد قبل الحفظ</p></div><button wire:click="$set('showBelowCostModal', false)" class="w-9 h-9 rounded-lg bg-white/15">✕</button></div>
            <div class="p-5 space-y-3">
                @foreach ($cart as $item)
                    @if ($item['quantity'] > 0 && (float)$item['price'] < (float)($item['cost_price'] ?? 0))
                        <div class="rounded-xl bg-amber-50 border border-amber-100 p-3 flex items-center justify-between gap-3"><div><div class="text-xs font-black text-slate-800">{{ $item['name'] }}</div><div class="text-[10px] text-slate-500 mt-1">التكلفة: <span class="font-mono">{{ number_format($item['cost_price'],2) }}</span></div></div><div class="text-left"><div class="text-[10px] text-amber-700 font-bold">سعر البيع</div><div class="font-mono font-black text-amber-800">{{ number_format($item['price'],2) }}</div></div></div>
                    @endif
                @endforeach
                <p class="text-xs font-bold text-slate-600 pt-1">هل تريد الاستمرار بهذا السعر؟</p>
                <div class="grid grid-cols-2 gap-2 pt-2"><button wire:click="confirmBelowCostCheckout" class="h-11 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-black">متابعة وحفظ</button><button wire:click="$set('showBelowCostModal', false)" class="h-11 rounded-xl bg-slate-100 text-slate-700 text-xs font-black">العودة للتعديل</button></div>
            </div>
        </div>
    </div>
@endif
