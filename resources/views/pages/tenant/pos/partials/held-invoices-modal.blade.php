  @if ($showHeldModal)
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden border border-slate-300">
                    <div class="bg-slate-800 text-white p-3 flex justify-between items-center">
                        <h3 class="text-sm font-bold">الفواتير المعلقة (F1 لإغلاق)</h3>
                        <button wire:click="$set('showHeldModal', false)"
                            class="text-slate-400 hover:text-white font-bold text-sm">✕</button>
                    </div>
                    <div class="p-3 max-h-80 overflow-y-auto space-y-2">
                        @forelse($heldInvoices as $index => $held)
                            <div
                                class="bg-slate-50 border border-slate-200 p-2.5 rounded-lg flex items-center justify-between hover:bg-slate-100">
                                <div>
                                    <div class="text-xs font-bold text-slate-800">فاتورة #{{ $held['id'] }}</div>
                                    <div class="text-[10px] text-slate-500 font-mono">{{ $held['time'] }}</div>
                                    <div class="text-xs font-bold text-indigo-700 font-mono mt-0.5">المجموع:
                                        {{ number_format($held['total'], 2) }}</div>
                                    @if (!empty($held['notes']))
                                        <div class="text-[10px] text-slate-600">ملاحظة: {{ $held['notes'] }}</div>
                                    @endif
                                </div>
                                <div class="flex gap-2">
                                    <button wire:click="restoreHeldInvoice({{ $index }})"
                                        class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded shadow active:scale-95 transition-all">
                                        استرجاع
                                    </button>
                                    <button wire:click="removeHeldInvoice({{ $index }})"
                                        class="px-2 py-1 bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold rounded shadow active:scale-95 transition-all">
                                        حذف
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-8 text-xs text-slate-400 font-semibold">
                                لا توجد فواتير معلقة حالياً.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
