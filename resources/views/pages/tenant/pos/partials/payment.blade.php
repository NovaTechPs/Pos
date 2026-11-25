{{--
    ملخص الدفع السفلي - تصميم POS
    - الإجمالي
    - الخصم
    - الصافي / المطلوب
    - المبلغ المدفوع
    - الباقي / المتبقي
    - طرق الدفع
    - الزبون
    - لوحة أرقام
    - حفظ / حفظ وطباعة / فاتورة جديدة
    - PDF
    - واتساب
--}}

@php
    $invoiceLocked = $this->invoiceIsLocked();

    /*
    |--------------------------------------------------------------------------
    | الزبون ورقم الهاتف لواتساب
    |--------------------------------------------------------------------------
    */

    $whatsappCustomer = null;
    $whatsappPhone = '';

    if ($selectedCustomerId) {
        $whatsappCustomer = \App\Models\Party::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('type', ['customer', 'supplier', 'both'])
            ->where('is_active', true)
            ->find((int) $selectedCustomerId);

        if ($whatsappCustomer && filled($whatsappCustomer->phone)) {
            $whatsappPhone = preg_replace(
                '/\D+/',
                '',
                (string) $whatsappCustomer->phone
            );

            /*
            | 00XXXXXXXX
            */
            if (str_starts_with($whatsappPhone, '00')) {
                $whatsappPhone = substr($whatsappPhone, 2);
            }

            /*
            | رقم فلسطيني:
            | 059XXXXXXX
            | يصبح:
            | 97059XXXXXXX
            */
            if (str_starts_with($whatsappPhone, '0')) {
                $whatsappPhone = '972' . substr($whatsappPhone, 1);
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | إنشاء نص الفاتورة لواتساب
    |--------------------------------------------------------------------------
    */

    $whatsappMessage = '';

    /*
    | إذا كانت هناك فاتورة محفوظة
    */
    if (!empty($receipt['invoice_no'])) {

        $whatsappMessage .= "🧾 فاتورة بيع\n";

        $whatsappMessage .=
            "رقم الفاتورة: " .
            ($receipt['invoice_no'] ?? '') .
            "\n";

        if (!empty($receipt['date'])) {

            $whatsappMessage .=
                "التاريخ: " .
                $receipt['date'];

            if (!empty($receipt['time'])) {
                $whatsappMessage .=
                    " " .
                    $receipt['time'];
            }

            $whatsappMessage .= "\n";
        }

        $whatsappMessage .= "\n";


        foreach (($receipt['items'] ?? []) as $item) {

            $nameWords = preg_split('/\s+/u', trim((string) ($item['name'] ?? 'منتج')), -1, PREG_SPLIT_NO_EMPTY);
            $shortName = implode(' ', array_slice($nameWords, 0, 2));
            $quantity = (float) ($item['qty'] ?? 0);
            $unitPrice = (float) ($item['price'] ?? 0);
            $lineTotal = (float) ($item['total'] ?? ($unitPrice * $quantity));

            $whatsappMessage .=
                "• " .
                $shortName .
                " ... " .
                number_format($unitPrice, 2) .
                " × " .
                $quantity .
                " = " .
                number_format($lineTotal, 2) .
                "\n";
        }


        $whatsappMessage .= "\n";


        $whatsappMessage .=
            "الإجمالي: " .
            ($receipt['total_amount'] ?? 0) .
            "\n";


        $whatsappMessage .=
            "المدفوع: " .
            ($receipt['paid'] ?? 0) .
            "\n";


        $whatsappMessage .=
            "الباقي: " .
            ($receipt['change'] ?? 0) .
            "\n";


        if (!empty($receipt['notice'])) {

            $whatsappMessage .=
                "\n" .
                $receipt['notice'];
        }

    } else {

        /*
        | الفاتورة الحالية قبل الحفظ
        */

        $whatsappMessage .= "🧾 فاتورة بيع\n\n";


        foreach ($cart as $item) {

            $nameWords = preg_split('/\s+/u', trim((string) ($item['name'] ?? 'منتج')), -1, PREG_SPLIT_NO_EMPTY);
            $shortName = implode(' ', array_slice($nameWords, 0, 2));
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['price'] ?? 0);
            $lineTotal = (float) ($item['subtotal'] ?? ($unitPrice * $quantity));

            $whatsappMessage .=
                "• " .
                $shortName .
                " ... " .
                number_format($unitPrice, 2) .
                " × " .
                $quantity .
                " = " .
                number_format($lineTotal, 2) .
                "\n";
        }


        $whatsappMessage .= "\n";


        $whatsappMessage .=
            "الإجمالي: " .
            number_format(
                (float) ($this->subtotal ?? 0),
                2
            ) .
            "\n";


        $whatsappMessage .=
            "الخصم: " .
            number_format(
                (float) ($this->calculated_discount ?? 0),
                2
            ) .
            "\n";


        $whatsappMessage .=
            "الصافي: " .
            number_format(
                (float) ($this->amountDue ?? 0),
                2
            ) .
            "\n";


        $whatsappMessage .=
            "المدفوع: " .
            number_format(
                (float) ($paid_amount ?? 0),
                2
            ) .
            "\n";


        $whatsappMessage .=
            "الباقي: " .
            number_format(
                (float) ($this->change ?? 0),
                2
            ) .
            "\n";


        if (!empty($notes)) {

            $whatsappMessage .=
                "\nملاحظات: " .
                $notes;
        }


        $whatsappMessage .=
            "\n\nشكراً لتعاملكم معنا 🌷";
    }
    $whatsappMessage .= "\nجميع الحقوق محفوظة © ".now()->year.' — WaslaPOS';
@endphp


<div
    class="pos-payment min-w-0 shrink-0 rounded-2xl border border-slate-200 bg-white p-2.5 shadow-sm"
    x-data="{ paymentSheetOpen: false }"
    x-on:keydown.escape.window="paymentSheetOpen = false"
    dir="rtl"
>
    <div x-cloak x-show="paymentSheetOpen" x-transition.opacity x-on:click="paymentSheetOpen = false" class="pos-payment-sheet-backdrop" aria-hidden="true"></div>

    <div class="pos-payment-grid grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-[repeat(13,minmax(0,1fr))]">
        {{-- على الجوال: نافذة سفلية لتفاصيل الدفع؛ على الشاشات الكبيرة تذوب داخل الشبكة (display: contents) --}}
        <div data-pos-payment-sheet id="pos-payment-sheet-{{ $this->getId() }}" class="pos-payment-sheet" x-bind:data-open="paymentSheetOpen ? 'true' : 'false'" aria-label="تفاصيل الدفع">
        <div class="pos-payment-sheet-head">
            <span class="pos-payment-sheet-handle" aria-hidden="true"></span>
            <div class="flex items-center justify-between">
                <h3 class="flex items-center gap-2 text-base font-black text-slate-900">
                    <flux:icon.credit-card class="size-5 text-blue-700" />
                    <span>تفاصيل الدفع</span>
                </h3>
                <button type="button" x-on:click="paymentSheetOpen = false" aria-label="إغلاق تفاصيل الدفع" class="flex size-9 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100">
                    <flux:icon.x-mark class="size-5" />
                </button>
            </div>
        </div>


        {{-- =========================================================
             ملخص المبالغ
        ========================================================== --}}

        <div class="pos-payment-totals md:order-1 lg:order-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:col-span-2 lg:col-span-6 lg:grid-cols-6">


            {{-- الإجمالي --}}

            <div data-pos-total-tile="subtotal" class="rounded-xl border border-slate-200 bg-slate-50 p-2">

                <div class="pos-tile-label text-[9px] font-black text-slate-500">
                    الإجمالي
                </div>

                <div class="mt-1 rounded-lg bg-white px-2 py-2 text-center">

                    <span class="font-mono text-base font-black text-slate-900">
                        {{ number_format($this->subtotal, 2) }}
                    </span>

                </div>

            </div>


            {{-- الخصم --}}

            <div data-pos-total-tile="discount" class="rounded-xl border border-slate-200 bg-slate-50 p-2">

                <div class="flex items-center justify-between">

                    <span class="pos-tile-label text-[9px] font-black text-slate-500">
                        <flux:icon.tag class="pos-tile-icon size-4" />
                        الخصم
                    </span>


                    <button
                        type="button"
                        wire:click="$set('discount_type', 'fixed')" @disabled($invoiceLocked)
                        class="text-[9px] font-black text-indigo-600"
                    >
                        {{ $discount_type === 'percentage' ? '%' : 'مبلغ' }}
                    </button>

                </div>


                <input
                    type="number"
                    min="0"
                    step="0.01"
                    wire:model.live.debounce.300ms="discount_amount" @disabled($invoiceLocked)
                    class="mt-1 h-[38px] w-full rounded-lg border border-slate-200 bg-white px-2 text-center font-mono text-base font-black text-indigo-700 outline-none focus:border-indigo-500"
                    placeholder="0.00"
                >

            </div>


            {{-- التوصيل --}}

            <div data-pos-total-tile="delivery" class="rounded-xl border border-slate-200 bg-slate-50 p-2">

                <div class="pos-tile-label text-[9px] font-black text-slate-500">
                    <flux:icon.truck class="pos-tile-icon size-4" />
                    التوصيل
                </div>

                <input
                    type="number"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    wire:model.live.debounce.300ms="delivery_fee" @disabled($invoiceLocked)
                    class="mt-1 h-[38px] w-full rounded-lg border border-slate-200 bg-white px-2 text-center font-mono text-base font-black text-indigo-700 outline-none focus:border-indigo-500"
                    placeholder="0.00"
                >

                @if ($delivery_fee > 0)
                    <button
                        type="button"
                        wire:click="openDeliveryDetails"
                        @disabled($invoiceLocked)
                        class="mt-2 w-full rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1.5 text-[10px] font-black text-indigo-700 hover:bg-indigo-100 disabled:opacity-50"
                    >
                        🚚 بيانات التوصيل
                    </button>
                @endif

            </div>


            {{-- المطلوب --}}

            <div data-pos-total-tile="net" class="rounded-xl border border-amber-200 bg-amber-50 p-2">

                <div class="pos-tile-label text-[9px] font-black text-amber-700">

                    {{ $this->total < 0 ? 'المسترد' : 'الصافي للدفع' }}

                </div>


                <div class="mt-1 rounded-lg bg-white px-2 py-2 text-center">

                    <input
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        wire:model.live.debounce.300ms="custom_final_total" @disabled($invoiceLocked)
                        class="w-full bg-transparent text-center font-mono text-base font-black text-amber-700 outline-none"
                        placeholder="0.00"
                    >

                </div>

            </div>


            {{-- المدفوع --}}

            <div data-pos-total-tile="paid" class="rounded-xl border border-indigo-200 bg-indigo-50 p-2">

                <div class="pos-tile-label text-[9px] font-black text-indigo-700">
                    <flux:icon.banknotes class="pos-tile-icon size-4" />

                    {{ $isReturnMode ? 'المصروف' : 'المدفوع' }}

                </div>


                <input
                    id="paid-amount-input"
                    type="number"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    wire:model.live.debounce.300ms="paid_amount" @disabled($invoiceLocked)
                    class="mt-1 h-[38px] w-full rounded-lg border-2 border-indigo-200 bg-white px-2 text-center font-mono text-base font-black text-indigo-800 outline-none focus:border-indigo-500"
                    placeholder="0.00"
                >

            </div>


            {{-- الباقي --}}

            <div data-pos-total-tile="change" class="rounded-xl border border-emerald-200 bg-emerald-50 p-2">

                <div
                    class="pos-tile-label text-[9px] font-black
                    {{ $this->remaining > 0
                        ? 'text-rose-700'
                        : 'text-emerald-700' }}"
                >
                    <flux:icon.wallet class="pos-tile-icon size-4" />
                    {{ $this->remaining > 0 ? 'المتبقي' : 'الباقي' }}

                </div>


                <div class="mt-1 rounded-lg bg-white px-2 py-2 text-center">

                    <span
                        class="font-mono text-base font-black
                        {{ $this->remaining > 0
                            ? 'text-rose-700'
                            : 'text-emerald-700' }}"
                    >

                        {{
                            number_format(
                                $this->remaining > 0
                                    ? $this->remaining
                                    : $this->change,
                                2
                            )
                        }}

                    </span>

                </div>

            </div>

        </div>



        {{-- =========================================================
             طرق الدفع
        ========================================================== --}}

        <div data-pos-payment-methods class="md:order-3 lg:order-2 rounded-xl border border-slate-200 bg-slate-50 p-2 lg:col-span-3">

            <div class="mb-1.5 flex items-center justify-between">

                <div>

                    <div class="text-[10px] font-black text-slate-800">
                        طريقة الدفع
                    </div>

                    <div class="text-[8px] font-bold text-slate-400">

                        {{
                            $isReturnMode
                                ? 'طريقة صرف المرتجع'
                                : 'اختر طريقة الدفع'
                        }}

                    </div>

                </div>


                <span
                    class="rounded-lg px-2 py-1 text-[8px] font-black
                    {{
                        $isReturnMode
                            ? 'bg-rose-50 text-rose-700'
                            : 'bg-emerald-50 text-emerald-700'
                    }}"
                >

                    {{ $isReturnMode ? 'مرتجع' : 'بيع' }}

                </span>

            </div>


            <div class="grid grid-cols-2 gap-1.5">

                @foreach ([
                    ['cash', 'نقدي', '💵', 'banknotes'],
                    ['card', 'بطاقة', '💳', 'credit-card'],
                    ['bank_transfer', 'تحويل', '🏦', 'building-library'],
                    ['cheque', 'شيك', '🧾', 'pencil-square']
                ] as [$value, $label, $icon, $methodIcon])

                    <button
                        type="button"
                        wire:click="$set('payment_method', '{{ $value }}')" @disabled($invoiceLocked)
                        class="rounded-xl border px-2 py-2.5 text-[10px] font-black transition
                            {{
                                $payment_method === $value
                                    ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm'
                                    : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-300 hover:bg-indigo-50'
                            }}"
                    aria-pressed="{{ $payment_method === $value ? 'true' : 'false' }}">

                        <span class="pos-method-emoji">{{ $icon }}</span>
                        <flux:icon :name="$methodIcon" class="pos-method-icon size-4" />
                        {{ $label }}

                    </button>

                @endforeach

            </div>

        </div>

        <div data-pos-mobile-save class="pos-mobile-save">
            <button type="button" x-on:click="saveAndPrint()" x-bind:disabled="printInProgress || {{ empty($cart) ? 'true' : 'false' }}"
                class="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-3 text-sm font-bold text-emerald-800 disabled:opacity-40">
                <flux:icon.printer class="size-5" />
                <span>{{ $invoiceLocked ? 'طباعة' : 'حفظ وطباعة' }}</span>
            </button>
            <button type="button" data-pos-payment-apply x-on:click="paymentSheetOpen = false"
                class="flex min-h-12 items-center justify-center gap-2 rounded-xl bg-blue-700 px-3 py-3 text-sm font-bold text-white">
                <flux:icon.check class="size-5" />
                <span>تطبيق</span>
            </button>
        </div>
        </div>



        {{-- =========================================================
             الزبون
        ========================================================== --}}

        <div data-pos-customer-notes class="md:order-2 lg:order-1 rounded-xl border border-slate-200 bg-slate-50 p-2 lg:col-span-4">
            @if ($selectedCustomerId && auth()->user()->can('reports.analytics') && auth()->user()->can('customers.view'))
                <a class="text-xs font-bold text-indigo-700" href="{{ route('tenant.reports', ['report' => 'statement', 'party_id' => $selectedCustomerId, 'party_type' => 'customer']) }}" target="_blank" rel="noopener">كشف حساب الزبون</a>
            @endif
            @if (filled($deliveryNotes))
                <p class="my-2 whitespace-pre-wrap text-xs text-slate-700">ملاحظة التوصيل: {{ $deliveryNotes }}</p>
            @endif


            <div class="pos-customer-head mb-1.5 flex items-center justify-between">

                <span class="pos-customer-label text-[10px] font-black text-slate-800">
                    <flux:icon.user class="pos-tile-icon size-5" />
                    الزبون
                </span>


                @if ($selectedCustomerId)

                    <button
                        type="button"
                        wire:click="clearCustomer" @disabled($invoiceLocked)
                        class="text-[9px] font-black text-rose-600 hover:text-rose-700"
                    >
                        إزالة
                    </button>

                @endif

            </div>


            @if ($selectedCustomerId)

                @php

                    $selectedCustomer =
                        $this->customerResults->firstWhere(
                            'id',
                            $selectedCustomerId
                        );

                    if (!$selectedCustomer) {

                        $selectedCustomer =
                            \App\Models\Party::query()
                                ->where('tenant_id', $this->tenantId())
                                ->whereKey($selectedCustomerId)
                                ->first();

                    }

                @endphp


                <button
                    type="button"
                    wire:click="openCustomerModal" @disabled($invoiceLocked)
                    class="pos-customer-pick pos-customer-selected w-full rounded-xl border border-indigo-200 bg-indigo-50 px-2 py-2 text-right hover:bg-indigo-100"
                >

                    <div class="truncate text-xs font-black text-indigo-800">

                        {{ $selectedCustomer?->name ?? 'الزبون المحدد' }}

                    </div>


                    @if ($selectedCustomer?->phone)

                        <div class="mt-0.5 text-[9px] font-bold text-indigo-500">

                            {{ $selectedCustomer->phone }}

                        </div>

                    @else

                        <div class="mt-0.5 text-[9px] font-bold text-rose-500">

                            بدون رقم هاتف

                        </div>

                    @endif

                </button>

            @else

                <button
                    type="button"
                    wire:click="openCustomerModal" @disabled($invoiceLocked)
                    class="pos-customer-pick w-full rounded-xl border border-indigo-200 bg-white px-3 py-2.5 text-xs font-black text-indigo-700 hover:bg-indigo-50"
                >

                    <span class="pos-customer-desktop-label">👤 إضافة زبون للفاتورة</span>
                    <span class="pos-customer-placeholder">اختر زبوناً…</span>
                    <flux:icon.chevron-down class="pos-customer-chevron size-4" />

                </button>

            @endif

            @if ($delivery_fee <= 0)
                <label class="mt-1.5 block text-[9px] font-black text-slate-600" data-pos-invoice-recipient>
                    اسم المستلم على الفاتورة
                    <input type="text" maxlength="191" autocomplete="off" wire:model.blur="invoiceRecipientName" @disabled($invoiceLocked)
                        placeholder="{{ $selectedCustomerId ? 'المستلم: اسم الزبون المحدد' : 'المستلم: زبون عام' }}" title="اسم المستلم على الفاتورة"
                        class="mt-0.5 h-8 w-full rounded-lg border border-slate-200 bg-white px-2 text-xs font-bold text-slate-900 outline-none focus:border-indigo-500 disabled:bg-slate-100">
                </label>
            @endif

            @can('customers.create')
                <a href="{{ route('tenant.customer') }}" target="_blank" rel="noopener" data-pos-add-customer aria-label="إضافة زبون جديد" title="إضافة زبون جديد"
                    class="pos-add-customer">
                    <flux:icon.user-plus class="size-5" />
                </a>
            @endcan




        </div>



        {{-- =========================================================
             لوحة الأرقام
        ========================================================== --}}




        {{-- =========================================================
             أزرار الفاتورة
        ========================================================== --}}



    </div>

    {{-- شريط الجوال الثابت: حفظ، تفاصيل الدفع، الصافي --}}
    <div data-pos-mobile-bar class="pos-mobile-bar">
        <button type="button" data-pos-mobile-bar-save wire:click="saveOrEditInvoice" wire:loading.attr="disabled" wire:target="saveOrEditInvoice,checkoutAndPrint" @disabled(empty($cart) && !$invoiceLocked)
            class="pos-mobile-bar-save {{ $isReturnMode ? 'bg-rose-600' : 'bg-blue-700' }}">
            <flux:icon.check class="size-5 shrink-0" />
            <span>{{ $invoiceLocked ? 'تعديل الفاتورة' : 'حفظ الفاتورة' }}</span>
        </button>
        <button type="button" data-pos-payment-details x-on:click="paymentSheetOpen = !paymentSheetOpen" x-bind:aria-expanded="paymentSheetOpen" aria-controls="pos-payment-sheet-{{ $this->getId() }}"
            class="pos-mobile-bar-details">
            <flux:icon.credit-card class="size-5 shrink-0" />
            <span>تفاصيل الدفع</span>
            <flux:icon.chevron-up class="size-4 shrink-0 transition" x-bind:class="paymentSheetOpen && 'rotate-180'" />
        </button>
        <div data-pos-mobile-net class="pos-mobile-bar-net {{ $this->total < 0 ? 'pos-mobile-bar-net-refund' : '' }}" role="status" aria-live="polite">
            <span class="pos-mobile-bar-net-label">{{ $this->total < 0 ? 'المسترد' : 'الصافي للدفع' }}</span>
            <strong dir="ltr"><span class="pos-mobile-bar-currency">{{ $this->currencySymbol() }}</span> {{ number_format($this->amountDue, 2) }}</strong>
        </div>
    </div>



    {{-- =========================================================
         نافذة اختيار الزبون
    ========================================================== --}}

    @if ($showCustomerModal)

        <div
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4"
            wire:key="customer-modal"
        >

            <div
                class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                dir="rtl"
                @click.stop
                data-pos-customer-picker
                x-data="{
                    move(step) {
                        const results = [...this.$root.querySelectorAll('[data-pos-customer-result]')];
                        const index = results.indexOf(document.activeElement);
                        if (step > 0) {
                            (index === -1 ? results[0] : results[index + 1])?.focus();
                        } else if (index <= 0) {
                            this.$refs.customerSearch?.focus();
                        } else {
                            results[index - 1].focus();
                        }
                    },
                }"
                x-on:keydown.escape.stop="$wire.set('showCustomerModal', false)"
            >

                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">

                    <div>

                        <div class="text-sm font-black text-slate-900">
                            اختيار الزبون
                        </div>

                        <div class="mt-0.5 text-[10px] font-bold text-slate-400">
                            يمكنك ترك الفاتورة بدون زبون
                        </div>

                    </div>


                    <button
                        type="button"
                        wire:click="$set('showCustomerModal', false)"
                        class="rounded-lg px-2 py-1 text-lg font-black text-slate-400 hover:bg-slate-100"
                    >
                        ×
                    </button>

                </div>


                <div class="p-3">

                    <input
                        wire:model.live.debounce.180ms="customerSearch"
                        type="text"
                        placeholder="ابحث باسم الزبون أو الهاتف..."
                        autocomplete="off"
                        autofocus
                        data-pos-customer-search
                        x-ref="customerSearch"
                        x-init="$nextTick(() => requestAnimationFrame(() => { $el.focus({ preventScroll: true }); $el.select(); }))"
                        x-on:keydown.arrow-down.prevent.stop="move(1)"
                        x-on:keydown.arrow-up.prevent.stop
                        x-on:keydown.enter.prevent.stop="$root.querySelector('[data-pos-customer-result]')?.click()"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-indigo-500 focus:bg-white"
                    >


                    <div class="mt-2 max-h-80 overflow-y-auto">

                        @forelse ($this->customerResults as $customer)

                            <button
                                type="button"
                                data-pos-customer-result data-search-option
                                wire:key="pos-customer-result-{{ $customer->id }}"
                                wire:click="selectCustomer({{ $customer->id }})"
                                x-on:keydown.arrow-down.prevent.stop="move(1)"
                                x-on:keydown.arrow-up.prevent.stop="move(-1)"
                                class="mb-1 flex w-full items-center justify-between gap-3 rounded-xl border border-transparent px-3 py-3 text-right outline-none hover:border-indigo-100 hover:bg-indigo-50 focus:border-indigo-300 focus:bg-indigo-50 focus:ring-2 focus:ring-indigo-100"
                            >

                                <div class="min-w-0">

                                    <div class="truncate text-sm font-black text-slate-800">

                                        {{ $customer->name }}

                                    </div>


                                    <div class="mt-0.5 text-[11px] font-semibold text-slate-400">

                                        {{ $customer->phone ?: 'بدون هاتف' }}

                                    </div>

                                </div>


                                <span class="text-indigo-600">
                                    اختيار
                                </span>

                            </button>

                        @empty

                            <div class="py-8 text-center text-xs font-bold text-slate-400">

                                لا يوجد زبائن مطابقون للبحث

                            </div>

                        @endforelse

                    </div>

                </div>

            </div>

        </div>

    @endif
  @if ($showCustomerPaymentModal)

        <div
            class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/60 p-4"
            wire:key="customer-payment-modal"
        >

            <div
                class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                dir="rtl"
                @click.stop
            >

                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <div>
                        <div class="text-sm font-black text-slate-900">
                            كم دفع الزبون؟
                        </div>
                        <div class="mt-0.5 text-[10px] font-bold text-slate-400">
                            تُحفظ ضمن فواتير المبيعات، ويُسجّل في الشيفت المبلغ المدفوع فقط. أدخل صفرًا للبيع الآجل.
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="$set('showCustomerPaymentModal', false)"
                        class="rounded-lg px-2 py-1 text-lg font-black text-slate-400 hover:bg-slate-100"
                    >
                        ×
                    </button>
                </div>

                <div class="p-4">

                    <div class="mb-3 grid grid-cols-2 gap-2">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-center">
                            <div class="text-[10px] font-black text-slate-500">إجمالي الفاتورة</div>
                            <div class="mt-1 font-mono text-xl font-black text-slate-900">
                                {{ number_format($this->amountDue, 2) }}
                            </div>
                        </div>

                        <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-center">
                            <div class="text-[10px] font-black text-indigo-600">المبلغ المدفوع</div>
                            <div class="mt-1 font-mono text-xl font-black text-indigo-700">
                                {{ number_format((float) $customerPaymentAmount, 2) }}
                            </div>
                        </div>
                    </div>

                    <div class="mb-3 grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            wire:click="setCustomerPaymentAmount(0)"
                            class="rounded-xl border border-slate-200 bg-white px-2 py-3 text-xs font-black text-slate-700 hover:border-slate-400 hover:bg-slate-50"
                        >
                            لم يدفع
                            <span class="mt-1 block font-mono text-[10px] text-slate-400">0.00</span>
                        </button>

                        <button
                            type="button"
                            wire:click="setCustomerPaymentAmount({{ $this->amountDue / 2 }})"
                            class="rounded-xl border border-amber-200 bg-amber-50 px-2 py-3 text-xs font-black text-amber-800 hover:bg-amber-100"
                        >
                            جزء
                            <span class="mt-1 block text-[10px] text-amber-600">نصف المبلغ</span>
                        </button>

                        <button
                            type="button"
                            wire:click="setCustomerPaymentAmount({{ $this->amountDue }})"
                            class="rounded-xl border border-emerald-200 bg-emerald-50 px-2 py-3 text-xs font-black text-emerald-800 hover:bg-emerald-100"
                        >
                            كامل
                            <span class="mt-1 block text-[10px] text-emerald-600">دفع كامل</span>
                        </button>
                    </div>

                    <label class="mb-1 block text-[10px] font-black text-slate-600">
                        أو أدخل المبلغ يدوياً
                    </label>

                    <input
                        type="number"
                        min="0"
                        max="{{ $this->amountDue }}"
                        step="0.01"
                        inputmode="decimal"
                        wire:model.live="customerPaymentAmount"
                        class="h-12 w-full rounded-xl border-2 border-indigo-200 bg-white px-3 text-center font-mono text-xl font-black text-indigo-800 outline-none focus:border-indigo-500"
                        autofocus
                    >

                    <div class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-center text-[10px] font-bold text-slate-500">
                        المتبقي بعد الدفع:
                        <span class="font-mono font-black text-rose-600">
                            {{ number_format(max(0, (float) $this->amountDue - (float) $customerPaymentAmount), 2) }}
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            wire:click="$set('showCustomerPaymentModal', false)"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs font-black text-slate-600 hover:bg-slate-50"
                        >
                            إلغاء
                        </button>

                        <button
                            type="button"
                            wire:click="confirmCustomerPayment"
                            class="rounded-xl bg-indigo-600 px-4 py-3 text-xs font-black text-white shadow-sm hover:bg-indigo-700"
                        >
                            تأكيد وحفظ الفاتورة
                        </button>
                    </div>

                </div>
            </div>
        </div>

    @endif

    {{-- =========================================================
         نافذة رقم هاتف الزبون / فتح واتساب واختيار الاسم
    ========================================================== --}}
    @if ($showCustomerPhoneModal)
        <div
            class="fixed inset-0 z-[130] flex items-center justify-center bg-slate-950/60 p-4"
            wire:key="customer-phone-modal"
        >
            <div
                class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                dir="rtl"
                @click.stop
            >
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <div>
                        <div class="text-sm font-black text-slate-900">الزبون لا يملك رقم هاتف</div>
                        <div class="mt-0.5 text-[10px] font-bold text-slate-400">أضف الرقم أو افتح واتساب واختر الزبون بالاسم.</div>
                    </div>
                    <button type="button" wire:click="$set('showCustomerPhoneModal', false)" class="rounded-lg px-2 py-1 text-lg font-black text-slate-400 hover:bg-slate-100">×</button>
                </div>

                <div class="space-y-3 p-4">
                    <input
                        type="text"
                        wire:model="customerPhoneInput"
                        inputmode="tel"
                        placeholder="رقم هاتف الزبون..."
                        autocomplete="off"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-center text-sm font-black outline-none focus:border-indigo-500 focus:bg-white"
                    >

                    @error('customerPhoneInput')
                        <div class="text-xs font-bold text-rose-600">{{ $message }}</div>
                    @enderror

                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            wire:click="saveCustomerPhone"
                            wire:loading.attr="disabled"
                            class="rounded-xl bg-emerald-600 px-3 py-3 text-xs font-black text-white hover:bg-emerald-700 disabled:opacity-60"
                        >
                            حفظ الرقم
                        </button>

                        <button
                            type="button"
                            onclick="
                                const message = @js($whatsappMessage);
                                if (!message) {
                                    alert('لا توجد بيانات فاتورة لإرسالها.');
                                    return;
                                }
                                window.open(
                                    'https://wa.me/?text=' + encodeURIComponent(message),
                                    '_blank'
                                );
                            "
                            class="rounded-xl border border-green-200 bg-green-50 px-3 py-3 text-xs font-black text-green-700 hover:bg-green-100"
                        >
                            فتح واتساب واختيار الاسم
                        </button>
                    </div>

                    <button
                        type="button"
                        wire:click="$set('showCustomerPhoneModal', false)"
                        class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50"
                    >
                        إلغاء
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showDeliveryDetailsModal)
        <div class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" dir="rtl">
            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="font-black text-slate-900">بيانات التوصيل</h2>
                        <p class="mt-1 text-xs text-slate-500">هذه البيانات ستظهر وتحفظ مع الفاتورة.</p>
                    </div>
                    <button type="button" wire:click="$set('showDeliveryDetailsModal', false)" class="h-9 w-9 rounded-lg bg-slate-100 text-slate-600">✕</button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-black text-slate-700">الاسم
                        <input autofocus type="text" wire:model="deliveryCustomerName" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-indigo-500">
                        @error('deliveryCustomerName')<span class="mt-1 block text-rose-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="text-xs font-black text-slate-700">رقم الهاتف
                        <input type="text" wire:model="deliveryCustomerPhone" inputmode="tel" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-indigo-500">
                        @error('deliveryCustomerPhone')<span class="mt-1 block text-rose-600">{{ $message }}</span>@enderror
                    </label>
                </div>
                <label class="mt-3 block text-xs font-black text-slate-700">المكان / العنوان
                    <textarea wire:model="deliveryCustomerAddress" rows="2" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-indigo-500"></textarea>
                    @error('deliveryCustomerAddress')<span class="mt-1 block text-rose-600">{{ $message }}</span>@enderror
                </label>
                <label class="mt-3 block text-xs font-black text-slate-700">ملاحظة التوصيل
                    <textarea wire:model="deliveryNotes" rows="2" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-indigo-500"></textarea>
                    @error('deliveryNotes')<span class="mt-1 block text-rose-600">{{ $message }}</span>@enderror
                </label>
                <div class="relative mt-3" x-data="{ activeDeliveryCompanyIndex: -1, deliveryCompaniesOpen: false }" x-on:click.outside="deliveryCompaniesOpen = false" x-on:keydown.escape.stop="deliveryCompaniesOpen = false">
                    <label class="text-xs font-black text-slate-700">شركة التوصيل
                        <input
                            type="search"
                            value="{{ $deliveryCompanySearch }}"
                            wire:model.live.debounce.200ms="deliveryCompanySearch"
                            x-on:focus="deliveryCompaniesOpen = true"
                            x-on:input="activeDeliveryCompanyIndex = -1; deliveryCompaniesOpen = $el.value.trim().length > 0"
                            x-on:keydown.arrow-down.prevent="activeDeliveryCompanyIndex = Math.min(activeDeliveryCompanyIndex + 1, {{ max(0, $this->deliveryCompanies->count() - 1) }})"
                            x-on:keydown.arrow-up.prevent="activeDeliveryCompanyIndex = Math.max(activeDeliveryCompanyIndex - 1, 0)"
                            x-on:keydown.enter.prevent="if (activeDeliveryCompanyIndex >= 0) $refs.deliveryCompanyResults.children[activeDeliveryCompanyIndex]?.click()"
                            placeholder="ابحث بالاسم أو الهاتف"
                            @class(['mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-indigo-500', 'font-black text-blue-700' => $deliveryCompanyId !== null])
                        >
                    </label>
                    @if (trim($this->deliveryCompanySearch) !== '')
                        <div x-cloak x-show="deliveryCompaniesOpen" x-ref="deliveryCompanyResults" class="absolute inset-x-0 top-full z-40 mt-1 max-h-40 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">
                            @forelse ($this->deliveryCompanies as $company)
                                <button
                                    type="button"
                                    wire:key="delivery-company-{{ $company->id }}"
                                    wire:click="selectDeliveryCompany({{ $company->id }})"
                                    x-on:click="deliveryCompaniesOpen = false"
                                    x-on:mouseenter="activeDeliveryCompanyIndex = {{ $loop->index }}"
                                    x-bind:class="activeDeliveryCompanyIndex === {{ $loop->index }} ? 'search-result-active' : ''"
                                    @class(['flex w-full justify-between px-3 py-2 text-right text-sm hover:bg-blue-50', 'bg-blue-50 font-black text-blue-700' => $deliveryCompanyId === $company->id])
                                ><span>{{ $company->name }}</span><span dir="ltr" class="text-slate-500">{{ $company->phone }}</span></button>
                            @empty
                                <p class="px-3 py-2 text-xs text-slate-500">لا توجد نتائج مطابقة.</p>
                            @endforelse
                        </div>
                    @endif
                    @error('deliveryCompanyId')<span class="mt-1 block text-rose-600">اختر شركة التوصيل.</span>@enderror
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2" data-pos-delivery-actions>
                    <button type="button" wire:click="confirmDeliveryDetails('checkout')" wire:loading.attr="disabled" wire:target="confirmDeliveryDetails"
                        data-pos-delivery-save
                        class="rounded-xl border border-indigo-200 bg-white px-4 py-3 text-sm font-black text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">💾 حفظ</button>
                    <button type="button" wire:click="confirmDeliveryDetails('checkoutAndPrint')" wire:loading.attr="disabled" wire:target="confirmDeliveryDetails"
                        data-pos-delivery-save-print
                        class="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white hover:bg-indigo-700 disabled:opacity-50">🖨️ حفظ وطباعة</button>
                </div>
            </div>
        </div>
    @endif

</div>
