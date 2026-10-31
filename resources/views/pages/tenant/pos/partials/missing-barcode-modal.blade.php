@if ($showMissingBarcodeModal)
    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-3" x-data x-trap.inert.noscroll.noautofocus="true" x-on:keydown.escape.prevent.stop="$wire.cancelMissingBarcode()">
        <section data-pos-missing-barcode-dialog tabindex="-1" x-init="$nextTick(() => $el.focus({ preventScroll: true }))" x-on:keydown.enter.self.prevent.stop x-on:keydown.stop role="dialog" aria-modal="true" aria-labelledby="missing-barcode-title" class="max-h-[90dvh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-amber-200 bg-white p-4 shadow-2xl sm:p-6" dir="rtl">
            <h2 id="missing-barcode-title" class="text-lg font-black text-amber-800">⚠ الباركود غير موجود</h2>
            <p class="mt-3 break-all text-center font-mono text-base font-bold">{{ $missingBarcode }}</p>
            <div class="mt-3 space-y-2 text-sm leading-7 text-slate-700">
                <p>رقم الباركود المدخل غير موجود في ملف الأصناف.</p>
                <p>اضغط <strong>نعم</strong> لإضافته لصنف جديد.</p>
                <p>اضغط <strong>لا</strong> لإضافة الباركود لصنف موجود مسبقًا.</p>
                <p>اضغط <strong>إلغاء</strong> لإنهاء العملية والرجوع لحقل الباركود في نقطة البيع.</p>
            </div>
            @error('missingBarcode') <p role="alert" class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror
            @if ($linkMissingBarcode)
                <div class="mt-5 rounded-2xl border border-indigo-100 bg-slate-50 p-3 sm:p-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <label for="missing-barcode-search" class="text-sm font-black text-slate-800">اختر الصنف الموجود</label>
                        <span class="rounded-full bg-indigo-100 px-2 py-1 text-[10px] font-bold text-indigo-700">ربط الباركود</span>
                    </div>
                    <div class="relative">
                        <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-indigo-400">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg>
                        </span>
                        <input id="missing-barcode-search" type="text" inputmode="search" autocomplete="off" wire:model.live.debounce.250ms="missingBarcodeSearch" maxlength="100" aria-describedby="missing-barcode-search-help" placeholder="اسم الصنف أو رقمه أو باركوده..." class="h-12 w-full rounded-xl border border-slate-200 bg-white pr-10 pl-12 text-sm font-semibold text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                        @if ($missingBarcodeSearch !== '')
                            <button type="button" wire:click="$set('missingBarcodeSearch', '')" aria-label="مسح بحث الأصناف" class="absolute inset-y-1 left-1 flex w-10 items-center justify-center rounded-lg text-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">×</button>
                        @endif
                    </div>
                    <p id="missing-barcode-search-help" class="mt-2 text-xs leading-5 text-slate-500">اختر الصنف من النتائج لإضافة الباركود إليه.</p>
                    <div wire:loading wire:target="missingBarcodeSearch,attachMissingBarcode" role="status" class="mt-2 text-xs font-bold text-indigo-600">جارٍ تنفيذ الطلب...</div>
                    <div data-pos-barcode-results class="mt-3 max-h-52 space-y-2 overflow-y-auto">
                        @forelse ($missingBarcodeProducts as $product)
                            <button data-pos-barcode-result type="button" wire:click="attachMissingBarcode({{ $product->id }})" wire:loading.attr="disabled" wire:target="attachMissingBarcode" class="group flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3 text-right shadow-sm transition hover:border-indigo-300 hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-indigo-500 disabled:cursor-wait disabled:opacity-50" wire:key="missing-barcode-product-{{ $product->id }}">
                                <span class="min-w-0">
                                    <span class="block break-words text-sm font-bold leading-6 text-slate-800">{{ $product->name }}</span>
                                    <span class="mt-1 block text-xs text-slate-400">رقم الصنف: <b class="font-mono font-medium text-slate-600">{{ $product->product_number ?: $product->id }}</b></span>
                                </span>
                                <span class="shrink-0 rounded-lg bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white">ربط</span>
                            </button>
                        @empty
                            <div class="rounded-xl border border-dashed border-slate-200 bg-white px-3 py-5 text-center text-sm leading-6 text-slate-400">{{ trim($missingBarcodeSearch) === '' ? 'ابدأ بكتابة اسم الصنف أو رقمه للبحث.' : 'لا توجد أصناف مطابقة. جرّب اسمًا أو رقمًا آخر.' }}</div>
                        @endforelse
                    </div>
                </div>
            @endif
            <div class="mt-5 grid grid-cols-3 gap-2">
                @if (auth()->user()?->can('products.create') && auth()->user()?->can('products.view') && mb_strlen($missingBarcode) <= 100)
                    <button type="button" wire:click="createProductFromMissingBarcode" class="rounded-xl bg-emerald-600 px-3 py-3 text-center text-sm font-bold text-white">نعم</button>
                @else
                    <button type="button" disabled title="تحتاج صلاحية إضافة وعرض الأصناف" class="rounded-xl bg-slate-100 px-3 py-3 text-sm text-slate-400">نعم</button>
                @endif
                @can('products.barcode.manage')
                    <button type="button" wire:click="chooseExistingProductForBarcode" class="rounded-xl bg-indigo-600 px-3 py-3 text-sm font-bold text-white">لا</button>
                @else
                    <button type="button" disabled title="تحتاج صلاحية إدارة الباركود" class="rounded-xl bg-slate-100 px-3 py-3 text-sm text-slate-400">لا</button>
                @endcan
                <button type="button" wire:click="cancelMissingBarcode" class="rounded-xl border border-slate-300 px-3 py-3 text-sm font-bold text-slate-700">إلغاء</button>
            </div>
            <p class="mt-3 text-xs text-slate-500">إضافة الصنف تفتح هنا داخل نقطة البيع مع الاحتفاظ بالفاتورة الحالية.</p>
        </section>
    </div>
@endif
