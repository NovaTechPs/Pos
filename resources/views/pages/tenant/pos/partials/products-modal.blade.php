  @if ($showProductsModal)
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-2 sm:p-4"
                x-data="{ show: @entangle('showProductsModal') }" x-effect="if (show) { setTimeout(() => $refs.f10SearchInput.focus(), 100) }">

                <div
                    class="bg-slate-200 rounded-xl shadow-2xl w-full max-w-6xl overflow-hidden border border-slate-400 flex flex-col h-[90vh]">

                    <!-- شريط العنوان والبحث العلوي -->
                    <div
                        class="bg-slate-300 border-b border-slate-400 p-2 flex flex-wrap items-center justify-between gap-2 shrink-0">
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="$set('showProductsModal', false)"
                                class="px-2.5 py-1 bg-slate-100 hover:bg-slate-50 border border-slate-400 rounded text-xs font-bold text-slate-800 shadow-sm active:scale-95">
                                (Esc) إلغاء / إغلاق
                            </button>
                        </div>

                        <!-- حقل البحث والاختيار -->
                        <div class="flex items-center gap-2 flex-1 max-w-2xl">
                            <select wire:model.live="selectedCategoryId"
                                class="bg-white border border-slate-400 text-xs font-bold rounded p-1.5 focus:outline-indigo-600">
                                <option value="">الكل</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>

                            <div class="relative flex-1">
                                <!-- حقل البحث المستقل والخاص بـ F10 مع التركيز التلقائي -->
                                <input type="text" x-ref="f10SearchInput"
                                    wire:model.live.debounce.200ms="productSearchQuery"
                                    placeholder="ابحث بالاسم أو الباركود... 🔍"
                                    class="w-full bg-white border border-slate-400 text-xs font-bold text-slate-900 rounded p-1.5 pl-7 focus:outline-indigo-600 shadow-inner">

                                @if (!empty($productSearchQuery))
                                    <button type="button" wire:click="$set('productSearchQuery', '')"
                                        class="absolute left-2 top-1.5 text-slate-400 hover:text-rose-600 font-bold text-xs">✕</button>
                                @endif
                            </div>

                            <button type="button" wire:click="loadQuickProducts"
                                class="p-1.5 bg-slate-100 hover:bg-slate-50 border border-slate-400 rounded text-xs font-bold text-slate-700">
                                🔄
                            </button>
                        </div>

                        <button wire:click="$set('showProductsModal', false)"
                            class="text-slate-600 hover:text-rose-600 font-bold text-base px-2">✕</button>
                    </div>

                    <!-- جدول عرض الأصناف (نمط برنامج الشامل) -->
                    <div class="flex-1 overflow-y-auto bg-slate-200 min-h-0 p-1">
                        <table class="w-full text-right text-xs border-collapse bg-slate-300">
                            <thead class="bg-slate-300 sticky top-0 font-bold text-slate-900 border-b-2 border-slate-400 shadow-sm">
                                <tr>
                                    <th class="p-1.5 border border-slate-400 text-center w-14">الرقم</th>
                                    <th class="p-1.5 border border-slate-400">الاسم</th>
                                    <th class="p-1.5 border border-slate-400 text-center w-24">المخزون</th>
                                    <th class="p-1.5 border border-slate-400 text-center w-28">سعر البيع</th>
                                    <th class="p-1.5 border border-slate-400">الباركود</th>
                                    <th class="p-1.5 border border-slate-400 text-center w-28">التاريخ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-300 font-bold">
                                @forelse($quickProducts as $index => $p)
                                    <tr wire:click="selectInlineProduct({{ $p->id }})"
                                        wire:key="modal-prod-{{ $p->id }}"
                                        class="cursor-pointer transition-colors border-b border-slate-300 {{ $index % 2 === 0 ? 'bg-slate-200' : 'bg-slate-100' }} hover:bg-indigo-100/80 active:bg-indigo-200">
                                        <td class="p-1.5 border border-slate-300 text-center font-mono text-slate-800">
                                            {{ $p->id }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-slate-900 font-bold">
                                            {{ $p->name }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-center font-mono {{ ($p->stock_quantity ?? 0) <= 0 ? 'text-rose-600' : 'text-slate-800' }}">
                                            {{ number_format((float) ($p->stock_quantity ?? 0), 0) }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-center font-mono text-slate-900">
                                            {{ number_format((float) ($p->retail_price ?? 0), 2) }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-slate-600 text-[11px] font-normal">
                                            {{ $p->barcode_value ?? '' }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-center font-mono text-[10px] text-slate-600">
                                            {{ $p->created_at?->format('Y-m-d') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-16 text-center text-slate-500 font-bold text-xs bg-slate-100">
                                            لا توجد أصناف مطابقة للبحث
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- الشريط السفلي -->
                    <div
                        class="p-2 bg-slate-300 border-t border-slate-400 flex justify-between items-center text-xs font-bold text-slate-700 shrink-0">
                        <span>عدد الأصناف المعروضة: {{ count($quickProducts) }}</span>
                        <button wire:click="$set('showProductsModal', false)"
                            class="px-4 py-1 bg-slate-100 hover:bg-slate-50 border border-slate-400 rounded text-xs font-bold text-slate-800 shadow-sm active:scale-95">
                            إغلاق (Esc)
                        </button>
                    </div>
                </div>
            </div>
        @endif
