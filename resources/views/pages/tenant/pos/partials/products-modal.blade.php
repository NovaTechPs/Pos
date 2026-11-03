@if ($showProductsModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-2 backdrop-blur-sm" x-data x-trap.noscroll="true">
        <div class="flex h-[92dvh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-2xl">
            <div class="shrink-0 border-b border-slate-200 bg-slate-900 p-3 text-white">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="text-sm font-black">{{ $selectingGift ? '🎁 اختر صنف الهدية — بدون مقابل' : '📦 قائمة الأصناف' }}</div>
                    <select wire:model.live="selectedCategoryId" class="rounded-lg border-0 bg-white px-2 py-1.5 text-xs font-bold text-slate-900">
                        <option value="">كل التصنيفات</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category['id'] ?? $category->id }}">{{ $category['name'] ?? $category->name }}</option>
                        @endforeach
                    </select>
                    <div class="relative min-w-0 basis-full sm:basis-auto sm:min-w-[200px] flex-1">
                        <input x-on:pos-search-cleared.window="if ($event.detail.componentId === $wire.$id) $el.value = ''" wire:model.live.debounce.350ms="productSearchQuery" type="text" placeholder="ابحث باسم المنتج أو الباركود..." class="w-full rounded-lg bg-white px-3 py-2 text-xs font-bold text-slate-900 outline-none">
                    </div>
                    <button wire:click="loadQuickProducts" class="rounded-lg bg-slate-700 px-3 py-2 text-xs font-black">تحديث</button>
                    <button wire:click="$set('showProductsModal', false)" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-black">إغلاق Esc</button>
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-auto bg-slate-50 p-2">
                @include('pages.tenant.pos.partials.quick-product-results')
            </div>
            <div class="shrink-0 border-t border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-500">عدد الأصناف: {{ count($quickProducts) }} · اضغط على الصنف لإضافته إلى الفاتورة</div>
        </div>
    </div>
@endif
