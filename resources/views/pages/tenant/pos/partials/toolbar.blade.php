@php
    $invoiceLocked = $this->invoiceIsLocked();
    $activeShift = $this->activeShift();
    $canDeleteInvoice = auth()->user()?->can('sales_invoices.delete') ?? false;
    $deleteInvoiceHint = !$canDeleteInvoice
        ? 'تحتاج صلاحية حذف فاتورة مبيعات'
        : (!$currentInvoiceId ? 'اختر فاتورة محفوظة من البحث أو زر السابقة' : 'حذف الفاتورة وإعادة كمياتها للمخزون');
    $toolbarButton = 'inline-flex h-10 shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40';
    $menuItem = 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40';
    $kbd = 'rounded border border-slate-200 bg-slate-100 px-1 py-0.5 font-mono text-[10px] font-black text-slate-500';
@endphp

<div x-data="{ mobileToolsOpen: false }" x-bind:data-expanded="mobileToolsOpen" class="pos-toolbar min-w-0 shrink-0 overflow-visible rounded-xl border border-slate-200 bg-white p-2 shadow-sm">

    <div class="pos-mobile-tools flex items-center gap-2" dir="rtl">
        <button type="button" x-on:click="mobileToolsOpen = !mobileToolsOpen" x-bind:aria-expanded="mobileToolsOpen" aria-controls="pos-toolbar-tools-{{ $this->getId() }}" class="min-h-11 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-bold text-slate-700">أدوات الفاتورة ▾</button>
        <button type="button" wire:click="saveOrEditInvoice" @disabled(empty($cart) && !$invoiceLocked) class="min-h-11 rounded-xl bg-slate-900 px-3 text-xs font-bold text-white disabled:opacity-40">{{ $invoiceLocked ? 'تعديل' : 'حفظ' }}</button>
        <button type="button" x-on:click="saveAndPrint()" x-bind:disabled="printInProgress || {{ empty($cart) ? 'true' : 'false' }}" class="min-h-11 rounded-xl bg-emerald-600 px-3 text-xs font-bold text-white disabled:opacity-40">{{ $invoiceLocked ? 'طباعة' : 'حفظ وطباعة' }}</button>
    </div>

    <div data-pos-toolbar-content id="pos-toolbar-tools-{{ $this->getId() }}">

        {{-- =========================================================
             الشريط الرئيسي: البحث والتنقل والإجراءات المتكررة
        ========================================================== --}}
        <div class="pos-toolbar-actions flex flex-wrap items-center gap-2" dir="rtl">

            @if (!Auth::user()->branch_id && !Auth::user()->isTenantOwner() && !Auth::user()->isSaaSAdmin())
                <select wire:model.live="selectedBranchId" aria-label="الفرع" class="h-10 rounded-lg border border-slate-300 bg-white px-2.5 text-sm font-bold text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                    <option value="">اختر الفرع</option>
                    @foreach ($branches as $branch)
                        <option wire:key="pos-branch-{{ $branch->id }}" value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif

            <form wire:submit.prevent="searchInvoice" class="flex min-w-[220px] flex-1 items-center">
                <div class="relative flex-1">
                    <flux:icon.magnifying-glass class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input wire:model="searchInvoiceQuery" data-pos-invoice-search type="text" placeholder="ابحث برقم الفاتورة ثم Enter..." aria-label="بحث عن فاتورة"
                        class="h-10 w-full rounded-lg border border-slate-300 bg-white pl-3 pr-9 text-sm font-bold text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                </div>
            </form>

            <div class="inline-flex h-10 shrink-0 overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm">
                <button type="button" wire:click="previousInvoice" title="الفاتورة السابقة" class="inline-flex items-center gap-1 px-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                    <flux:icon.chevron-right class="size-4" /> السابقة
                </button>
                <button type="button" wire:click="nextInvoice" @disabled(!$currentInvoiceId) title="الفاتورة التالية" class="inline-flex items-center gap-1 border-r border-slate-300 px-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">
                    التالية <flux:icon.chevron-left class="size-4" />
                </button>
            </div>

            <button type="button" wire:click="$set('showProductsModal', true)" class="{{ $toolbarButton }}">
                <flux:icon.squares-2x2 class="size-4 text-blue-600" /> الأصناف <kbd class="{{ $kbd }}">F10</kbd>
            </button>

            <button type="button" wire:click="$set('showHeldModal', true)" class="relative {{ $toolbarButton }}">
                <flux:icon.queue-list class="size-4 text-slate-500" /> معلقة <kbd class="{{ $kbd }}">F1</kbd>
                @if (count($heldInvoices))
                    <span class="absolute -left-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full border-2 border-white bg-amber-500 px-1 text-[10px] font-black text-white">{{ count($heldInvoices) }}</span>
                @endif
            </button>

            <button type="button" wire:click="holdInvoice" @disabled(empty($cart) || $currentInvoiceId) class="{{ $toolbarButton }}">
                <flux:icon.pause-circle class="size-4 text-amber-600" /> تعليق <kbd class="{{ $kbd }}">F2</kbd>
            </button>

            <button type="button" wire:click="toggleReturnMode"
                class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-sm font-bold shadow-sm transition
                    {{ $isReturnMode ? 'border-rose-600 bg-rose-600 text-white hover:bg-rose-700' : 'border-slate-300 bg-white text-slate-700 hover:bg-rose-50 hover:text-rose-700' }}">
                <flux:icon.arrow-uturn-left class="size-4" /> {{ $isReturnMode ? 'وضع المرتجع مفعل' : 'مرتجع' }}
            </button>

            <button type="button" wire:click="clearCart" class="{{ $toolbarButton }}">
                <flux:icon.plus-circle class="size-5 text-blue-600" /> فاتورة جديدة <kbd class="{{ $kbd }}">F4</kbd>
            </button>

            @unless ($activeShift)
                <button type="button" wire:click="triggerOpenShiftModal" class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-lg bg-emerald-600 px-4 text-sm font-black text-white shadow-sm transition hover:bg-emerald-500">
                    <flux:icon.lock-open class="size-4" /> فتح الشيفت
                </button>
            @endunless

            {{-- أدوات أقل استخداماً --}}
            <div class="relative shrink-0" x-data="{ toolsOpen: false }" x-on:click.outside="toolsOpen = false" x-on:keydown.escape.stop="toolsOpen = false">
                <button type="button" data-pos-tools-menu x-on:click="toolsOpen = !toolsOpen" x-bind:aria-expanded="toolsOpen" aria-haspopup="menu" class="{{ $toolbarButton }}">
                    أدوات <flux:icon.chevron-down class="size-4" />
                </button>

                <div x-cloak x-show="toolsOpen" x-transition.origin.top x-on:click="toolsOpen = false" role="menu" class="absolute left-0 top-full z-50 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl">
                    <button type="button" role="menuitem" data-pos-notes-button x-on:click="notesOpen = true" class="{{ $menuItem }}">
                        <flux:icon.chat-bubble-left-ellipsis class="size-4 text-slate-500" /> ملاحظات الفاتورة {{ filled($notes) ? '•' : '' }}
                    </button>

                    @can('pos.sale.checkout')
                        <button type="button" role="menuitem" wire:click="startGiftSelection" @disabled($invoiceLocked || $isReturnMode) class="{{ $menuItem }}">
                            <flux:icon.gift class="size-4 text-fuchsia-600" /> إضافة هدية
                        </button>
                    @endcan

                    <button type="button" role="menuitem" wire:click="openCostModal" @disabled(empty($cart)) class="{{ $menuItem }}">
                        <flux:icon.currency-dollar class="size-4 text-slate-500" /> التكلفة
                    </button>

                    @if ($activeShift)
                        @can('accounting.voucher.create')
                            <button type="button" role="menuitem" wire:click="openExpenseModal" class="{{ $menuItem }}">
                                <flux:icon.banknotes class="size-4 text-slate-500" /> مصروف من الصندوق
                            </button>
                        @endcan
                    @endif

                    <div class="my-1 border-t border-slate-100"></div>

                    <button type="button" role="menuitem" wire:click="downloadInvoicePdf" title="إنشاء PDF للفاتورة" class="{{ $menuItem }}">
                        <flux:icon.document-arrow-down class="size-4 text-slate-500" /> PDF الفاتورة
                    </button>
                    <button type="button" role="menuitem" wire:click="copyLoadedInvoice" @disabled(empty($cart)) title="نسخ الفاتورة إلى فاتورة جديدة" class="{{ $menuItem }}">
                        <flux:icon.document-duplicate class="size-4 text-slate-500" /> نسخ إلى فاتورة جديدة
                    </button>
                    <button type="button" role="menuitem" x-on:click="window.__posWhatsAppWindow = window.open('about:blank', '_blank')" wire:click="sendInvoiceWhatsApp" wire:loading.attr="disabled" title="حفظ الفاتورة أولاً ثم إرسالها عبر واتساب" class="{{ $menuItem }} text-emerald-700 hover:bg-emerald-50">
                        <flux:icon.share class="size-4" /> واتساب (حفظ وإرسال)
                    </button>

                    @if ($activeShift)
                        <div class="my-1 border-t border-slate-100"></div>
                        <button type="button" role="menuitem" wire:click="prepareCloseShift" class="{{ $menuItem }}">
                            <flux:icon.lock-closed class="size-4 text-slate-500" /> إغلاق الشيفت
                        </button>
                    @endif

                    <div class="my-1 border-t border-slate-100"></div>
                    <button type="button" role="menuitem" data-pos-delete-invoice wire:click="deleteInvoice"
                        @disabled(!$canDeleteInvoice || !$currentInvoiceId)
                        title="{{ $deleteInvoiceHint }}"
                        wire:confirm="هل تريد حذف هذه الفاتورة وإلغاء سنداتها وإعادة كمياتها للمخزون؟"
                        wire:loading.attr="disabled" wire:target="deleteInvoice"
                        class="{{ $menuItem }} text-rose-700 hover:bg-rose-50">
                        <flux:icon.trash class="size-4" />
                        <span>حذف الفاتورة</span>
                        @if (!$canDeleteInvoice || !$currentInvoiceId)
                            <span class="mr-auto text-[10px] font-medium text-slate-400">{{ !$canDeleteInvoice ? 'بدون صلاحية الحذف' : 'اختر فاتورة محفوظة' }}</span>
                        @endif
                    </button>
                </div>
            </div>
        </div>

        {{-- =========================================================
             سطر معلومات الفاتورة (للعرض فقط)
        ========================================================== --}}
        <div data-pos-invoice-info class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-slate-100 pt-2 text-xs font-bold text-slate-500" dir="rtl">
            <span title="{{ $currentInvoiceId ? 'اضغط تعديل لتغيير الفاتورة؛ الطباعة والنسخ متاحان مباشرة.' : '' }}" class="inline-flex items-center gap-1.5 {{ $currentInvoiceId ? 'text-amber-700' : 'text-emerald-700' }}">
                <span class="h-2 w-2 rounded-full {{ $currentInvoiceId ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
                {{ $currentInvoiceId ? 'عرض فاتورة محفوظة — للقراءة فقط' : 'فاتورة جديدة جاهزة' }}
            </span>
            @if ($currentInvoiceId)
                <span class="font-mono text-slate-700">#{{ $this->receipt['invoice_no'] ?? $currentInvoiceId }}</span>
            @endif

            <span class="inline-flex items-center gap-1.5 {{ $activeShift ? 'text-emerald-700' : 'text-rose-700' }}">
                <span class="h-2 w-2 rounded-full {{ $activeShift ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                {{ $activeShift ? 'شيفت #'.$activeShift->id.' مفتوح' : 'لا يوجد شيفت مفتوح' }}
            </span>

            <span
                x-data="{ browserOnline: navigator.onLine }"
                x-on:online.window="browserOnline = true"
                x-on:offline.window="browserOnline = false"
                class="inline-flex items-center gap-1.5"
                :class="browserOnline ? 'text-emerald-700' : 'text-amber-700'"
                role="status"
                aria-live="polite"
            >
                <span class="inline-block h-2 w-2 rounded-full" :class="browserOnline ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                <span x-show="browserOnline">أونلاين / Online</span>
                <span x-show="!browserOnline">أوفلاين / Offline</span>
            </span>

            @include('pages.tenant.pos.partials.connection-status')

            <span class="inline-flex items-center gap-1"><flux:icon.calendar class="size-3.5" /> {{ $this->invoiceDate }}</span>
            <span class="inline-flex items-center gap-1"><flux:icon.user class="size-3.5" /> {{ $this->invoiceCreator }}</span>

            @if (filled($notes))
                <button type="button" x-on:click="notesOpen = true" class="inline-flex items-center gap-1 text-indigo-700 hover:underline"><flux:icon.chat-bubble-left-ellipsis class="size-3.5" /> يوجد ملاحظة</button>
            @endif

            @if ($isReturnMode)
                <span class="inline-flex items-center gap-1 text-rose-700"><flux:icon.arrow-uturn-left class="size-3.5" /> مرتجع نقدي — أضف الأصناف ثم احفظ لصرف المبلغ</span>
            @endif
        </div>
    </div>
</div>

<template x-teleport="body">
    <div x-cloak x-show="notesOpen" x-effect="if (notesOpen) $nextTick(() => $refs.notesInput?.focus())" x-on:keydown.escape.window="if (notesOpen) notesOpen = false" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-label="ملاحظات الفاتورة" dir="rtl">
        <section x-on:click.outside="notesOpen = false" class="w-full max-w-lg rounded-2xl bg-white p-4 shadow-xl">
            <div class="mb-3 flex items-center justify-between">
                <label for="pos-notes-{{ $this->getId() }}" class="font-bold text-slate-900">ملاحظات الفاتورة</label>
                <button type="button" x-on:click="notesOpen = false" aria-label="إغلاق الملاحظات" class="h-10 w-10 rounded-lg bg-slate-100">✕</button>
            </div>
            <textarea x-ref="notesInput" id="pos-notes-{{ $this->getId() }}" data-pos-notes-input wire:model.live.debounce.300ms="notes" @disabled($this->invoiceIsLocked()) rows="4" placeholder="ملاحظة الفاتورة..." class="w-full rounded-xl border border-slate-200 p-3 text-base text-slate-900 focus:border-indigo-500"></textarea>
            <button type="button" x-on:click="notesOpen = false" class="mt-3 rounded-xl bg-indigo-600 px-5 py-2 text-sm font-bold text-white">تم</button>
        </section>
    </div>
</template>
