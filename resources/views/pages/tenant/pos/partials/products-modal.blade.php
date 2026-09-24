@if ($showProductsModal)
    <div class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm p-3 md:p-6 flex items-center justify-center" x-data="{ show: @entangle('showProductsModal') }" x-effect="if (show) { setTimeout(() => $refs.f10SearchInput.focus(), 100) }">
        <div class="w-full max-w-6xl h-[90vh] rounded-2xl bg-white shadow-2xl overflow-hidden border border-slate-200 flex flex-col">
            <div class="p-4 border-b border-slate-100 bg-white shrink-0">
                <div class="flex items-center justify-between gap-3">
                    <div><h2 class="text-lg font-black text-slate-900">قائمة الأصناف</h2><p class="text-[11px] text-slate-400 mt-0.5">اختر الصنف لإضافته مباشرة إلى الفاتورة</p></div>
                    <button type="button" wire:click="$set('showProductsModal', false)" class="w-10 h-10 rounded-xl bg-slate-100 hover:bg-rose-50 hover:text-rose-600 text-slate-500 font-black">✕</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-2 mt-4">
                    <select wire:model.live="selectedCategoryId" class="md:col-span-3 h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-bold outline-none focus:border-indigo-500"><option value="">كل التصنيفات</option>@foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach</select>
                    <div class="relative md:col-span-8"><span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">🔎</span><input x-ref="f10SearchInput" type="text" wire:model.live.debounce.200ms="productSearchQuery" placeholder="ابحث باسم المنتج أو الباركود..." class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 pr-10 pl-10 text-sm font-bold outline-none focus:border-indigo-500">@if($productSearchQuery)<button type="button" wire:click="$set('productSearchQuery','')" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">✕</button>@endif</div>
                    <button type="button" wire:click="loadQuickProducts" class="md:col-span-1 h-11 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 font-black">↻</button>
                </div>
            </div>

            <div class="flex-1 overflow-auto bg-slate-50 p-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                    @forelse($quickProducts as $p)
                        @php $stock = (float)($p->stock_quantity ?? 0); @endphp
                        <button type="button" wire:click="selectInlineProduct({{ $p->id }})" wire:key="modal-prod-{{ $p->id }}" class="text-right rounded-2xl border border-slate-200 bg-white hover:border-indigo-300 hover:shadow-md transition p-4 group">
                            <div class="flex items-start justify-between gap-2"><span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg">📦</span><span class="px-2 py-1 rounded-lg text-[10px] font-black {{ $stock > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $stock > 0 ? 'متوفر' : 'نفد المخزون' }}</span></div>
                            <div class="mt-3 text-sm font-black text-slate-800 line-clamp-2 min-h-10">{{ $p->name }}</div>
                            <div class="mt-3 flex items-end justify-between"><div><div class="text-[10px] text-slate-400 font-bold">سعر البيع</div><div class="font-mono font-black text-indigo-700">{{ number_format((float)($p->retail_price ?? 0),2) }}</div></div><div class="text-left"><div class="text-[10px] text-slate-400 font-bold">المخزون</div><div class="font-mono font-black {{ $stock > 0 ? 'text-slate-800' : 'text-rose-600' }}">{{ number_format($stock,0) }}</div></div></div>
                            <div class="mt-3 pt-2 border-t border-slate-100 text-[10px] text-slate-400 font-mono truncate">{{ $p->barcode_value ?? 'بدون باركود' }}</div>
                        </button>
                    @empty
                        <div class="col-span-full py-24 text-center"><div class="text-3xl">🔎</div><div class="mt-3 text-sm font-black text-slate-600">لا توجد أصناف مطابقة</div><div class="text-xs text-slate-400 mt-1">جرّب كلمة بحث أخرى أو غيّر التصنيف</div></div>
                    @endforelse
                </div>
            </div>
            <div class="px-4 py-3 border-t border-slate-100 bg-white flex items-center justify-between"><span class="text-xs font-bold text-slate-500">{{ count($quickProducts) }} صنف معروض</span><button type="button" wire:click="$set('showProductsModal', false)" class="h-10 px-5 rounded-xl bg-slate-900 text-white text-xs font-black">إغلاق <span class="font-mono text-[10px]">Esc</span></button></div>
        </div>
    </div>
@endif
