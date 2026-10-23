<div class="shrink-0 overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-2.5 shadow-[0_4px_20px_rgba(15,23,42,0.06)]">

    {{-- =========================================================
         الشريط الرئيسي
    ========================================================== --}}
    <div class="flex flex-wrap items-center gap-2">

        {{-- الفرع --}}
        @if (!Auth::user()->branch_id)

            <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-1.5">

                <span class="text-[10px] font-black text-slate-500">
                    الفرع
                </span>

                <select
                    wire:model.live="selectedBranchId"
                    class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-700 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                >
                    <option value="">اختر الفرع</option>

                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>

            </div>

        @endif


        {{-- =====================================================
             البحث
        ====================================================== --}}
        <form
            wire:submit.prevent="searchInvoice"
            class="flex min-w-[230px] flex-1 items-center gap-1.5"
        >

            <div class="relative flex-1">

                <input
                    wire:model="searchInvoiceQuery"
                    data-pos-invoice-search
                    type="text"
                    placeholder="ابحث برقم الفاتورة أو ID..."
                    class="h-[42px] w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-bold text-slate-700 placeholder:text-slate-400 outline-none transition focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-100"
                >

            </div>

            <button
                type="submit"
                class="flex h-[42px] items-center justify-center gap-1.5 rounded-xl bg-indigo-600 px-4 text-xs font-black text-white shadow-sm transition hover:bg-indigo-700 hover:shadow-md active:scale-[0.98]"
            >
                <span>بحث</span>
                <span class="text-sm">⌕</span>
            </button>

        </form>


        {{-- =====================================================
             السابق / التالي
        ====================================================== --}}
        <div class="flex items-center gap-1.5">

            <button
                wire:click="previousInvoice"
                class="flex h-[42px] items-center gap-1 rounded-xl border border-slate-200 bg-white px-3 text-[10px] font-black text-slate-700 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 active:scale-[0.98]"
            >
                <span class="text-sm">←</span>
                <span>السابقة</span>
            </button>

            <button
                wire:click="nextInvoice"
                @disabled(!$currentInvoiceId)
                class="flex h-[42px] items-center gap-1 rounded-xl border border-slate-200 bg-white px-3 text-[10px] font-black text-slate-700 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 disabled:cursor-not-allowed disabled:opacity-35 active:scale-[0.98]"
            >
                <span>التالية</span>
                <span class="text-sm">→</span>
            </button>

        </div>


        @can('pos.sale.checkout')
            <button type="button" wire:click="startGiftSelection"
                @disabled($this->invoiceIsLocked() || $isReturnMode)
                class="flex h-[42px] items-center gap-2 rounded-xl bg-fuchsia-600 px-4 text-xs font-black text-white transition hover:bg-fuchsia-700 disabled:opacity-50">
                🎁 إضافة هدية
            </button>
        @endcan

        @can('sales_invoices.delete')
            @if ($currentInvoiceId)
                <button type="button" wire:click="deleteInvoice"
                    wire:confirm="هل تريد حذف هذه الفاتورة وإلغاء سنداتها وإعادة كمياتها للمخزون؟"
                    wire:loading.attr="disabled" wire:target="deleteInvoice"
                    class="flex h-[42px] items-center gap-2 rounded-xl bg-red-600 px-4 text-xs font-black text-white transition hover:bg-red-700 disabled:opacity-50">
                    حذف الفاتورة
                </button>
            @endif
        @endcan

        {{-- =====================================================
             فاتورة جديدة
        ====================================================== --}}
        <button
            wire:click="startNewInvoice"
            class="flex h-[42px] items-center gap-2 rounded-xl bg-emerald-600 px-4 text-xs font-black text-white shadow-[0_4px_12px_rgba(16,185,129,0.18)] transition hover:bg-emerald-700 hover:shadow-md active:scale-[0.98]"
        >
            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-white/20 text-base">
                +
            </span>

            <span>
                فاتورة جديدة
            </span>
        </button>


        {{-- =====================================================
             الشيفت
        ====================================================== --}}
        @if ($this->activeShift())

            <button
                wire:click="prepareCloseShift"
                class="flex h-[42px] items-center gap-2 rounded-xl bg-slate-900 px-4 text-xs font-black text-white shadow-[0_4px_12px_rgba(15,23,42,0.18)] transition hover:bg-slate-800 hover:shadow-md active:scale-[0.98]"
            >
                <span class="text-sm">🔒</span>

                <span>
                    إغلاق الشيفت
                </span>
            </button>

        @else

            <button
                wire:click="triggerOpenShiftModal"
                class="flex h-[42px] items-center gap-2 rounded-xl bg-emerald-600 px-4 text-xs font-black text-white shadow-[0_4px_12px_rgba(16,185,129,0.18)] transition hover:bg-emerald-700 hover:shadow-md active:scale-[0.98]"
            >
                <span class="text-sm">🔓</span>

                <span>
                    فتح الشيفت
                </span>
            </button>

        @endif

        <div
            x-data="{ browserOnline: navigator.onLine }"
            x-on:online.window="browserOnline = true"
            x-on:offline.window="browserOnline = false"
            class="flex h-[42px] items-center gap-2 rounded-xl border px-3 text-xs font-black"
            :class="browserOnline ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-300 bg-amber-50 text-amber-800'"
            role="status"
            aria-live="polite"
        >
            <span class="inline-block h-2.5 w-2.5 rounded-full" :class="browserOnline ? 'bg-emerald-500' : 'bg-amber-500'"></span>
            <strong x-show="browserOnline">أونلاين / Online</strong>
            <strong x-show="!browserOnline">أوفلاين / Offline</strong>
        </div>

        {{-- =====================================================
             المرتجع
        ====================================================== --}}
        <button
            wire:click="toggleReturnMode"
            class="flex h-[42px] items-center gap-2 rounded-xl border px-4 text-xs font-black shadow-sm transition active:scale-[0.98]
                {{
                    $isReturnMode
                        ? 'border-rose-500 bg-rose-600 text-white shadow-[0_4px_12px_rgba(244,63,94,0.18)] hover:bg-rose-700'
                        : 'border-slate-200 bg-white text-slate-700 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700'
                }}"
        >
            <span class="text-sm">↩</span>

            <span>
                {{ $isReturnMode ? 'وضع المرتجع مفعل' : 'مرتجع' }}
            </span>
        </button>


        {{-- =====================================================
             التكلفة
        ====================================================== --}}
        <button
            wire:click="openCostModal"
            @disabled(empty($cart))
            class="flex h-[42px] items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 text-xs font-black text-indigo-700 shadow-sm transition hover:border-indigo-300 hover:bg-indigo-100 hover:text-indigo-800 disabled:cursor-not-allowed disabled:opacity-35 active:scale-[0.98]"
        >
            <span class="text-sm">◇</span>

            <span>
                التكلفة
            </span>
        </button>


        {{-- =====================================================
             تعليق
        ====================================================== --}}
        <button
            wire:click="holdInvoice"
            @disabled(empty($cart) || $currentInvoiceId)
            class="flex h-[42px] items-center gap-2 rounded-xl border border-amber-300 bg-amber-300 px-4 text-xs font-black text-slate-900 shadow-[0_4px_12px_rgba(245,158,11,0.12)] transition hover:border-amber-400 hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-35 active:scale-[0.98]"
        >

            <span class="text-sm">↻</span>

            <span>
                تعليق
            </span>

            <kbd class="rounded-md bg-amber-700 px-1.5 py-0.5 text-[9px] font-black text-white shadow-sm">
                F2
            </kbd>

        </button>


        {{-- =====================================================
             الفواتير المعلقة
        ====================================================== --}}
        <button
            wire:click="$set('showHeldModal', true)"
            class="relative flex h-[42px] items-center gap-2 rounded-xl bg-slate-800 px-4 text-xs font-black text-white shadow-[0_4px_12px_rgba(15,23,42,0.15)] transition hover:bg-slate-900 active:scale-[0.98]"
        >

            <span>
                معلقة
            </span>

            <kbd class="rounded-md bg-slate-950 px-1.5 py-0.5 text-[9px] font-black text-white">
                F1
            </kbd>

            @if (count($heldInvoices))

                <span
                    class="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full border-2 border-white bg-rose-500 px-1 text-[9px] font-black text-white shadow-sm"
                >
                    {{ count($heldInvoices) }}
                </span>

            @endif

        </button>


        {{-- =====================================================
             الأصناف
        ====================================================== --}}
        <button
            wire:click="$set('showProductsModal', true)"
            class="flex h-[42px] items-center gap-2 rounded-xl bg-indigo-600 px-4 text-xs font-black text-white shadow-[0_4px_12px_rgba(79,70,229,0.18)] transition hover:bg-indigo-700 hover:shadow-md active:scale-[0.98]"
        >

            <span class="text-sm">
                📦
            </span>

            <span>
                الأصناف
            </span>

            <kbd class="rounded-md bg-indigo-800 px-1.5 py-0.5 text-[9px] font-black text-white">
                F10
            </kbd>

        </button>

    </div>


    {{-- =========================================================
         شريط معلومات الفاتورة
    ========================================================== --}}
    <div class="mt-2.5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2.5">

        {{-- حالة الفاتورة --}}
        <span
            class="flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-[10px] font-black
                {{
                    $currentInvoiceId
                        ? 'border-amber-200 bg-amber-50 text-amber-700'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-700'
                }}"
        >

            <span class="h-1.5 w-1.5 rounded-full
                {{
                    $currentInvoiceId
                        ? 'bg-amber-500'
                        : 'bg-emerald-500'
                }}"
            ></span>

            {{
                $currentInvoiceId
                    ? 'عرض فاتورة محفوظة — للقراءة فقط'
                    : 'فاتورة جديدة جاهزة'
            }}

        </span>


        {{-- التاريخ --}}
        <span class="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] font-bold text-slate-600">
            <span>📅</span>
            <span>{{ $this->invoiceDate }}</span>
        </span>


        {{-- المستخدم --}}
        <span class="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] font-bold text-slate-600">
            <span>👤</span>
            <span>{{ $this->invoiceCreator }}</span>
        </span>


        {{-- حالة الشيفت --}}
        @if ($this->activeShift())

            <span class="flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[10px] font-black text-emerald-700">

                <span class="h-2 w-2 rounded-full bg-emerald-500 shadow-[0_0_0_3px_rgba(16,185,129,0.12)]"></span>

                <span>
                    شيفت #{{ $this->activeShift()->id }} مفتوح
                </span>

            </span>

        @else

            <span class="flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-[10px] font-black text-rose-700">

                <span class="h-2 w-2 rounded-full bg-rose-500"></span>

                <span>
                    لا يوجد شيفت مفتوح
                </span>

            </span>

        @endif


        {{-- وضع المرتجع --}}
        @if ($isReturnMode)

            <span class="flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-[10px] font-black text-rose-700">
                <span class="text-sm">↩</span>
                <span>وضع المرتجع</span>
            </span>

        @endif

    </div>

</div>
