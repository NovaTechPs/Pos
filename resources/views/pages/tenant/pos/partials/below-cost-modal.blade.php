   @if ($showBelowCostModal)
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-rose-300">
                    <div class="bg-rose-600 text-white p-4 flex justify-between items-center font-bold text-sm">
                        <span class="flex items-center gap-2">
                            <span class="text-lg">⚠️</span>
                            <span>تنبيه: السعر أقل من التكلفة</span>
                        </span>
                        <button wire:click="$set('showBelowCostModal', false)"
                            class="text-rose-100 hover:text-white font-bold">✕</button>
                    </div>

                    <div class="p-4 space-y-3">
                        <p class="text-xs font-bold text-slate-700 leading-relaxed">
                            تحذير! تحتوي الفاتورة على منتجات يتم بيعها بأسعار أقل من التكلفة:
                        </p>

                        <div class="max-h-48 overflow-y-auto space-y-2">
                            @foreach ($cart as $item)
                                @if ($item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0))
                                    <div
                                        class="bg-rose-50 border border-rose-200 p-2 rounded-lg text-xs flex justify-between items-center font-bold">
                                        <div>
                                            <div class="text-slate-900">{{ $item['name'] }}</div>
                                            <div class="text-[10px] text-rose-700">التكلفة:
                                                {{ number_format($item['cost_price'], 2) }}</div>
                                        </div>
                                        <div class="text-rose-700 font-mono text-sm">
                                            سعر البيع: {{ number_format($item['price'], 2) }}
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <p class="text-xs text-slate-600 font-semibold">هل تريد الاستمرار وإتمام الفاتورة بهذا السعر؟
                        </p>

                        <div class="flex items-center gap-2 pt-2">
                            <button wire:click="confirmBelowCostCheckout"
                                class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 px-3 rounded-xl text-xs shadow transition-all active:scale-95">
                                نعم، استمرار
                            </button>
                            <button wire:click="$set('showBelowCostModal', false)"
                                class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold py-2.5 px-3 rounded-xl text-xs transition-all">
                                إلغاء لتعديل السعر
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
