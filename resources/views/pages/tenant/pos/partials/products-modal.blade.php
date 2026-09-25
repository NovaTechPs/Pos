@if ($showProductsModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-2 backdrop-blur-sm" x-data x-trap.noscroll="true">
        <div class="flex h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-2xl">
            <div class="shrink-0 border-b border-slate-200 bg-slate-900 p-3 text-white">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="text-sm font-black">📦 قائمة الأصناف</div>
                    <select wire:model.live="selectedCategoryId" class="rounded-lg border-0 bg-white px-2 py-1.5 text-xs font-bold text-slate-900">
                        <option value="">كل التصنيفات</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category['id'] ?? $category->id }}">{{ $category['name'] ?? $category->name }}</option>
                        @endforeach
                    </select>
                    <div class="relative min-w-[250px] flex-1">
                        <input wire:model.live.debounce.200ms="productSearchQuery" type="text" placeholder="ابحث باسم المنتج أو الباركود..." class="w-full rounded-lg bg-white px-3 py-2 text-xs font-bold text-slate-900 outline-none">
                    </div>
                    <button wire:click="loadQuickProducts" class="rounded-lg bg-slate-700 px-3 py-2 text-xs font-black">تحديث</button>
                    <button wire:click="$set('showProductsModal', false)" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-black">إغلاق Esc</button>
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-auto bg-slate-50 p-2">
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @forelse ($quickProducts as $product)
                        <button wire:click="selectInlineProduct({{ $product->id }})" class="rounded-2xl border border-slate-200 bg-white p-3 text-right shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md">
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-black text-slate-900">{{ $product->name }}</div>
                                <span class="rounded-lg px-2 py-1 text-[9px] font-black {{ $product->stock_quantity > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ number_format((float) $product->stock_quantity, 0) }}</span>
                            </div>
                            <div class="mt-3 flex items-end justify-between">
                                <div>
                                    <div class="text-[9px] font-bold text-slate-400">سعر البيع</div>
                                    <div class="font-mono text-base font-black text-indigo-700">{{ number_format((float) $product->retail_price, 2) }}</div>
                                </div>
                                <div class="text-left text-[9px] font-mono text-slate-400">{{ $product->barcode_value ?: 'بدون باركود' }}</div>
                            </div>
                        </button>
                    @empty
                        <div class="col-span-full py-24 text-center text-xs font-black text-slate-400">لا توجد أصناف مطابقة.</div>
                    @endforelse
                </div>
            </div>
            <div class="shrink-0 border-t border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-500">عدد الأصناف: {{ count($quickProducts) }} · اضغط على الصنف لإضافته إلى الفاتورة</div>
        </div>
    </div>
@endif
