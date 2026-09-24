  @if ($showCloseShiftModal)
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-300">
                    <div class="bg-slate-900 text-white p-3 flex justify-between items-center">
                        <h3 class="text-sm font-bold flex items-center gap-1.5">
                            <span>🔒</span>
                            <span>إغلاق الشيفت ومطابقة الصندوق</span>
                        </h3>
                        <button wire:click="$set('showCloseShiftModal', false)"
                            class="text-slate-400 hover:text-white font-bold">✕</button>
                    </div>

                    <div class="p-4 space-y-3 text-xs">
                        <div
                            class="grid grid-cols-2 gap-2 bg-slate-50 p-3 rounded-lg border border-slate-200 font-bold">
                            <div>الرصيد الافتتاحي: <span
                                    class="font-mono text-indigo-700">{{ number_format($shift_opening_cash, 2) }}</span>
                            </div>
                            <div>المبيعات النقدية: <span
                                    class="font-mono text-emerald-600">{{ number_format($shift_total_sales, 2) }}</span>
                            </div>
                            <div>المرتجعات النقدية: <span
                                    class="font-mono text-rose-600">{{ number_format($shift_total_returns, 2) }}</span>
                            </div>
                            <div class="col-span-2 border-t pt-2 text-sm text-slate-900">
                                المتوقع بالدرج: <span
                                    class="font-mono font-black text-amber-600">{{ number_format($shift_expected_cash, 2) }}</span>
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">المبلغ الفعلي الموجود بالدرج بعد
                                العدّ:</label>
                            <input type="number" step="0.01" wire:model.live.debounce.300ms="actual_cash"
                                class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-lg font-black font-mono text-center focus:outline-indigo-600">
                        </div>

                        @php
                            $diff = (float) $actual_cash - $shift_expected_cash;
                        @endphp

                        <div
                            class="p-2.5 rounded-lg text-center font-bold text-xs {{ $diff == 0 ? 'bg-emerald-100 text-emerald-800' : ($diff < 0 ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">
                            @if ($diff == 0)
                                الصندوق مطابق تماماً 👌
                            @elseif($diff < 0)
                                يوجد عجز بمقدار: {{ number_format(abs($diff), 2) }} ⚠️
                            @else
                                يوجد زيادة بمقدار: {{ number_format($diff, 2) }} 💡
                            @endif
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">ملاحظات الإغلاق (اختياري):</label>
                            <textarea wire:model="shift_notes" rows="2"
                                class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-xs focus:outline-indigo-600"></textarea>
                        </div>

                        <button wire:click="closeShift"
                            class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold p-3 rounded-xl text-xs shadow transition-all active:scale-95">
                            تأكيد إغلاق الشيفت وتصفية الصندوق
                        </button>
                    </div>
                </div>
            </div>
        @endif
