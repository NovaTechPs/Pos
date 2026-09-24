     <div class="space-y-1.5 shrink-0" x-data="{ showNumpad: false }">
                    <!-- حقل المبلغ المدفوع ومحاكي لوحة الأرقام المنبثقة -->
                    <div class="relative">
                        <div class="flex justify-between items-center mb-0.5">
                            <label class="text-[10px] font-bold text-slate-600">المبلغ المدفوع (نقداً):</label>
                            <button type="button" @click="showNumpad = !showNumpad"
                                class="text-[10px] text-indigo-600 font-bold underline">
                                [لوحة الأرقام]
                            </button>
                        </div>
                        <input type="number" wire:model.live="paid_amount" @focus="showNumpad = true"
                            class="w-full text-lg font-black font-mono text-left bg-slate-50 border border-slate-300 rounded-lg p-1 focus:outline-none focus:border-indigo-600 text-indigo-900">

                        <!-- لوحة الأرقام تظهر فقط عند الحاجة بأسلوب Popover -->
                        <div x-show="showNumpad" @click.outside="showNumpad = false" x-cloak
                            class="absolute bottom-full mb-1 left-0 right-0 bg-white border border-slate-300 shadow-2xl rounded-lg p-2 z-50">
                            <div class="grid grid-cols-3 gap-1 font-bold font-mono text-xs">
                                @foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '.', 'C'] as $num)
                                    <button type="button" wire:click="appendNumpad('{{ $num }}')"
                                        class="py-1.5 bg-slate-100 hover:bg-slate-200 border rounded text-center active:bg-slate-300">
                                        {{ $num }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <!-- أزرار الإجراءات بأقصى توفير للمساحة -->
                    <div class="grid grid-cols-2 gap-1.5">
                        <button wire:click="checkoutAndPrint" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif
                            class="col-span-2 {{ $this->total < 0 ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' }} disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg font-bold py-2 px-3 shadow active:scale-95 transition-all text-xs flex justify-between items-center">
                            <span>🖨️ {{ $this->total < 0 ? 'حفظ وطباعة المرتجع' : 'حفظ وطباعة' }}</span>
                            <span
                                class="text-[9px] {{ $this->total < 0 ? 'bg-rose-800' : 'bg-emerald-800' }} text-white px-1.5 py-0.5 rounded font-mono">F6</span>
                        </button>

                        <button wire:click="checkout" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif
                            class="bg-slate-700 hover:bg-slate-800 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg font-bold py-1.5 px-2 shadow active:scale-95 transition-all text-[11px] flex justify-between items-center">
                            <span>حفظ فقط</span>
                            <span class="text-[9px] bg-slate-900 text-white px-1 rounded font-mono">F3</span>
                        </button>

                        <button wire:click="clearCart" @if (count($cart) === 0) disabled @endif
                            class="bg-slate-500 hover:bg-slate-600 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg font-bold py-1.5 px-2 shadow active:scale-95 transition-all text-[11px] flex justify-between items-center">
                            <span>تنظيف السلة</span>
                            <span class="text-[9px] bg-slate-700 text-white px-1 rounded font-mono">F4</span>
                        </button>
                    </div>
                </div>
