    <div
                    class="bg-white border border-slate-300 rounded-xl p-2 flex flex-wrap items-center justify-between gap-2 shadow-sm shrink-0">

                    @if (!Auth::user()->branch_id)
                        <div class="flex items-center gap-2" wire:key="branch-selector-container">
                            <span class="text-xs font-bold text-slate-700">الفرع:</span>
                            <select wire:model.live="selectedBranchId" wire:key="branch-select-input"
                                class="bg-slate-50 border border-slate-300 text-xs font-bold rounded-lg p-1.5 focus:outline-indigo-600">
                                <option value="">-- اختر الفرع --</option>
                                @foreach ($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <!-- نموذج البحث عن فاتورة -->
                    <form wire:submit.prevent="searchInvoice" class="flex items-center gap-1">
                        <input type="text" wire:model="searchInvoiceQuery" placeholder="رقم الفاتورة أو ID..."
                            class="w-36 bg-slate-50 border border-slate-300 rounded-lg p-1.5 text-xs font-bold focus:outline-indigo-600">
                        <button type="submit"
                            class="px-2.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold active:scale-95 transition-all">
                            بحث 🔍
                        </button>
                    </form>

                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="previousInvoice"
                            class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded-lg text-xs font-bold text-slate-700 flex items-center gap-1 active:scale-95 transition-all">
                            <span>➔</span>
                            <span>الفاتورة السابقة</span>
                        </button>
                        <button type="button" wire:click="nextInvoice"
                            @if (!$currentInvoiceId) disabled @endif
                            class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 border border-slate-300 rounded-lg text-xs font-bold text-slate-700 flex items-center gap-1 active:scale-95 transition-all">
                            <span>الفاتورة التالية</span>
                            <span>⬅</span>
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($activeShift)
                            <button type="button" wire:click="prepareCloseShift"
                                class="px-3 py-1.5 bg-rose-700 hover:bg-rose-800 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1 shadow-sm">
                                <span>🔒</span>
                                <span>إغلاق الشيفت</span>
                            </button>
                        @else
                            <button type="button" wire:click="triggerOpenShiftModal"
                                class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1 shadow-sm">
                                <span>🔓</span>
                                <span>فتح شيفت جديد</span>
                            </button>
                        @endif

                        <button type="button" wire:click="toggleReturnMode"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1 border {{ $isReturnMode ? 'bg-rose-600 text-white border-rose-700 animate-pulse' : 'bg-slate-100 text-slate-700 border-slate-300 hover:bg-slate-200' }}">
                            <span>🔄</span>
                            <span>{{ $isReturnMode ? 'وضع المرتجع (مفعل)' : 'وضع المرتجع' }}</span>
                        </button>

                        <button type="button" wire:click="openCostModal"
                            @if (count($cart) === 0) disabled @endif
                            class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1">
                            <span>🔍 استطلاع التكلفة</span>
                        </button>

                        <button type="button" wire:click="holdInvoice"
                            @if (count($cart) === 0 || $currentInvoiceId) disabled @endif
                            class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1">
                            <span>تعليق</span>
                            <span class="text-[10px] bg-amber-700 text-white px-1.5 py-0.5 rounded font-mono">F2</span>
                        </button>

                        <button type="button" wire:click="$set('showHeldModal', true)"
                            class="relative px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1">
                            <span>معلقة</span>
                            <span class="text-[10px] bg-slate-900 text-white px-1.5 py-0.5 rounded font-mono">F1</span>
                            @if (count($heldInvoices) > 0)
                                <span
                                    class="absolute -top-1.5 -right-1.5 bg-rose-500 text-white text-[10px] rounded-full w-4 h-4 flex items-center justify-center font-bold">
                                    {{ count($heldInvoices) }}
                                </span>
                            @endif
                        </button>

                        <button type="button" wire:click="$set('showProductsModal', true)"
                            class="px-3 py-1.5 bg-purple-700 hover:bg-purple-800 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1 shadow-sm">
                            <span>📦 الأصناف</span>
                            <span
                                class="text-[10px] bg-purple-900 text-white px-1.5 py-0.5 rounded font-mono">F10</span>
                        </button>
                        <div
                            class="flex items-center gap-1.5 bg-slate-100 border border-slate-300 px-2.5 py-1 rounded-md text-xs font-bold text-slate-700">
                            <span>📅</span>
                            <span class="font-mono text-slate-900">{{ $this->invoiceDate }}</span>
                        </div>
                        <div
                            class="flex items-center gap-1.5 bg-indigo-50 border border-indigo-200 px-2.5 py-1 rounded-md text-xs font-bold text-indigo-900">
                            <span>👤</span>
                            <span>بواسطة: {{ $this->invoiceCreator }}</span>
                        </div>
                        @if ($currentInvoiceId)
                            <span
                                class="bg-amber-100 text-amber-800 border border-amber-300 px-2.5 py-1 rounded-md text-xs font-bold font-mono">
                                عرض فاتورة #{{ $currentInvoiceId }}
                            </span>
                        @else
                            <span
                                class="bg-emerald-100 text-emerald-800 border border-emerald-300 px-2.5 py-1 rounded-md text-xs font-bold">
                                فاتورة جديدة
                            </span>
                        @endif
                    </div>
                </div>
