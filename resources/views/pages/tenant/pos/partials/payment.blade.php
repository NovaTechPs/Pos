{{--
    ملخص الدفع السفلي - تصميم POS
    - الإجمالي
    - الخصم
    - الصافي / المطلوب
    - المبلغ المدفوع
    - الباقي / المتبقي
    - طرق الدفع
    - العميل
    - لوحة أرقام
    - حفظ / حفظ وطباعة / فاتورة جديدة
    - PDF
    - واتساب
--}}

@php
    $invoiceLocked = $this->invoiceIsLocked();

    /*
    |--------------------------------------------------------------------------
    | العميل ورقم الهاتف لواتساب
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
    class="pos-payment min-w-0 shrink-0 rounded-xl border border-slate-200 bg-white p-2 shadow-sm"
    x-data
    dir="rtl"
>
    @php
        $amountLabelClass = 'text-xs font-bold text-slate-500';
        $smallInputClass = 'mt-1 h-10 w-full rounded-lg border border-slate-300 bg-white px-2 text-center font-mono text-base font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50';
        $hasRemaining = $this->remaining > 0;
    @endphp

    <div class="pos-payment-grid grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-[repeat(13,minmax(0,1fr))]">

        {{-- =========================================================
             العميل
        ========================================================== --}}
        <div data-pos-customer-notes class="md:order-2 lg:order-1 flex flex-col rounded-lg border border-slate-200 bg-slate-50 p-2 lg:col-span-2">
            <div class="mb-1.5 flex items-center justify-between">
                <span class="text-xs font-bold text-slate-600">العميل</span>
                @if ($selectedCustomerId)
                    <button type="button" wire:click="clearCustomer" @disabled($invoiceLocked) class="text-[11px] font-bold text-rose-600 hover:text-rose-700">إزالة</button>
                @endif
            </div>

            @if ($selectedCustomerId)
                @php
                    $selectedCustomer = $this->customerResults->firstWhere('id', $selectedCustomerId)
                        ?? \App\Models\Party::query()->where('tenant_id', $this->tenantId())->whereKey($selectedCustomerId)->first();
                @endphp
                <button type="button" wire:click="openCustomerModal" @disabled($invoiceLocked) class="w-full rounded-lg border border-emerald-300 bg-emerald-50 px-2 py-2 text-right hover:bg-emerald-100">
                    <div class="truncate text-sm font-black text-emerald-900">{{ $selectedCustomer?->name ?? 'العميل المحدد' }}</div>
                    @if ($selectedCustomer?->phone)
                        <div class="mt-0.5 text-[11px] font-bold text-emerald-700">{{ $selectedCustomer->phone }}</div>
                    @else
                        <div class="mt-0.5 text-[11px] font-bold text-rose-500">بدون رقم هاتف</div>
                    @endif
                </button>
                @if (auth()->user()->can('reports.analytics') && auth()->user()->can('customers.view'))
                    <a class="mt-1 text-[11px] font-bold text-indigo-700 hover:underline" href="{{ route('tenant.reports', ['report' => 'statement', 'party_id' => $selectedCustomerId, 'party_type' => 'customer']) }}" target="_blank" rel="noopener">كشف حساب الزبون</a>
                @endif
            @else
                <button type="button" wire:click="openCustomerModal" @disabled($invoiceLocked) class="flex w-full flex-1 items-center justify-center gap-1.5 rounded-lg border border-dashed border-slate-300 bg-white px-2 py-2.5 text-sm font-bold text-slate-700 hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700 disabled:opacity-50">
                    <flux:icon.user-plus class="size-4" /> إضافة زبون للفاتورة
                </button>
            @endif

            @if (filled($deliveryNotes))
                <p class="mt-1.5 whitespace-pre-wrap text-[11px] text-slate-600">ملاحظة التوصيل: {{ $deliveryNotes }}</p>
            @endif
        </div>

        {{-- =========================================================
             طرق الدفع
        ========================================================== --}}
        <div data-pos-payment-methods class="md:order-3 lg:order-2 rounded-lg border border-slate-200 bg-slate-50 p-2 lg:col-span-3">
            <div class="mb-1.5 flex items-center justify-between">
                <span class="text-xs font-bold text-slate-600">{{ $isReturnMode ? 'طريقة صرف المرتجع' : 'طريقة الدفع' }}</span>
                <span class="rounded-md px-2 py-0.5 text-[11px] font-black {{ $isReturnMode ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $isReturnMode ? 'مرتجع' : 'بيع' }}</span>
            </div>
            <div class="grid grid-cols-2 gap-1.5">
                @foreach ([
                    ['cash', 'نقدي', 'banknotes'],
                    ['card', 'بطاقة', 'credit-card'],
                    ['bank_transfer', 'تحويل', 'building-library'],
                    ['cheque', 'شيك', 'document-text'],
                ] as [$value, $label, $icon])
                    <button type="button" wire:key="pos-payment-method-{{ $value }}" wire:click="$set('payment_method', '{{ $value }}')" @disabled($invoiceLocked)
                        class="inline-flex items-center justify-center gap-1 rounded-lg border px-2 py-2.5 text-sm font-bold transition
                            {{ $payment_method === $value ? 'border-blue-600 bg-blue-600 text-white shadow-sm' : 'border-slate-300 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50' }}">
                        <flux:icon :name="$icon" class="size-4" /> {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- =========================================================
             المبالغ: الأرقام المهمة أكبر
        ========================================================== --}}
        <div class="pos-payment-totals md:order-1 lg:order-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:col-span-2 lg:col-span-6 lg:grid-cols-[repeat(9,minmax(0,1fr))]">

            {{-- الإجمالي --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-2 lg:col-span-1">
                <div class="{{ $amountLabelClass }}">الإجمالي</div>
                <div class="mt-1 flex h-10 items-center justify-center rounded-lg bg-white font-mono text-base font-black text-slate-900">{{ number_format($this->subtotal, 2) }}</div>
            </div>

            {{-- الخصم --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-2 lg:col-span-1">
                <div class="flex items-center justify-between">
                    <span class="{{ $amountLabelClass }}">الخصم</span>
                    <button type="button" wire:click="$set('discount_type', 'fixed')" @disabled($invoiceLocked) class="text-[11px] font-black text-blue-600">{{ $discount_type === 'percentage' ? '%' : 'مبلغ' }}</button>
                </div>
                <input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="discount_amount" @disabled($invoiceLocked) class="{{ $smallInputClass }} text-rose-600" placeholder="0.00">
            </div>

            {{-- التوصيل --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-2 lg:col-span-1">
                <div class="{{ $amountLabelClass }}">التوصيل</div>
                <input type="number" min="0" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="delivery_fee" @disabled($invoiceLocked) class="{{ $smallInputClass }}" placeholder="0.00">
                @if ($delivery_fee > 0)
                    <button type="button" wire:click="openDeliveryDetails" @disabled($invoiceLocked) class="mt-1 inline-flex w-full items-center justify-center gap-1 rounded-md border border-blue-200 bg-blue-50 px-1 py-1 text-[11px] font-bold text-blue-700 hover:bg-blue-100 disabled:opacity-50">
                        <flux:icon.truck class="size-3.5" /> بيانات التوصيل
                    </button>
                @endif
            </div>

            {{-- الصافي للدفع --}}
            <div class="rounded-lg border-2 border-slate-800 bg-slate-900 p-2 text-white lg:col-span-2">
                <div class="text-xs font-bold text-slate-300">{{ $this->total < 0 ? 'المسترد' : 'الصافي للدفع' }}</div>
                <input type="number" min="0" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="custom_final_total" @disabled($invoiceLocked)
                    aria-label="{{ $this->total < 0 ? 'المسترد' : 'الصافي للدفع' }}"
                    title="المبلغ المطلوب. اكتب مبلغاً آخر لتعديل الإجمالي النهائي."
                    class="mt-1 h-12 w-full rounded-lg bg-transparent text-center font-mono text-3xl font-black text-white outline-none placeholder:text-white focus:bg-white/10" placeholder="{{ number_format(abs($this->total), 2, '.', '') }}">
            </div>

            {{-- المدفوع --}}
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-2 lg:col-span-2">
                <div class="text-xs font-bold text-blue-800">{{ $isReturnMode ? 'المصروف' : 'المدفوع' }}</div>
                <input id="paid-amount-input" type="number" min="0" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="paid_amount" @disabled($invoiceLocked)
                    class="mt-1 h-12 w-full rounded-lg border-2 border-blue-300 bg-white px-2 text-center font-mono text-2xl font-black text-blue-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100" placeholder="0.00">
            </div>

            {{-- الباقي / المتبقي --}}
            <div class="rounded-lg border p-2 lg:col-span-2 {{ $hasRemaining ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-emerald-50' }}">
                <div class="text-xs font-bold {{ $hasRemaining ? 'text-rose-700' : 'text-emerald-700' }}">{{ $hasRemaining ? 'المتبقي' : 'الباقي' }}</div>
                <div class="mt-1 flex h-12 items-center justify-center rounded-lg bg-white font-mono text-3xl font-black {{ $hasRemaining ? 'text-rose-700' : 'text-emerald-700' }}">
                    {{ number_format($hasRemaining ? $this->remaining : $this->change, 2) }}
                </div>
            </div>
        </div>

        {{-- =========================================================
             الحفظ والطباعة بجانب المبالغ
        ========================================================== --}}
        <div data-pos-save-actions class="md:order-4 md:col-span-2 grid grid-cols-2 gap-2 lg:order-4 lg:col-span-2 lg:grid-cols-1">
            <button type="button" x-on:click="saveAndPrint()" x-bind:disabled="printInProgress || {{ empty($cart) ? 'true' : 'false' }}"
                title="{{ $invoiceLocked ? 'طباعة الفاتورة المحفوظة' : 'حفظ الفاتورة وطباعتها' }}"
                class="flex flex-col items-center justify-center rounded-lg px-2 py-2 text-white shadow-sm transition disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400 {{ $isReturnMode ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-500' }}">
                <span class="inline-flex items-center gap-1.5 text-base font-black"><flux:icon.printer class="size-5" /> {{ $invoiceLocked ? 'طباعة' : 'حفظ وطباعة' }}</span>
                <span class="text-[11px] font-bold opacity-80">F6</span>
            </button>
            <button type="button" wire:click="saveOrEditInvoice" @disabled(empty($cart) && !$invoiceLocked)
                class="flex flex-col items-center justify-center rounded-lg px-2 py-2 shadow-sm transition disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400 {{ $invoiceLocked ? 'border border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100' : 'bg-slate-900 text-white hover:bg-slate-800' }}">
                <span class="inline-flex items-center gap-1.5 text-sm font-black">
                    @if ($invoiceLocked)
                        <flux:icon.pencil-square class="size-4" /> تعديل
                    @else
                        <flux:icon.check-circle class="size-4" /> حفظ
                    @endif
                </span>
                <span class="text-[11px] font-bold opacity-80">F3</span>
            </button>
        </div>
    </div>



    {{-- =========================================================
         نافذة اختيار العميل
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
                                data-pos-customer-result
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
                                    x-bind:class="activeDeliveryCompanyIndex === {{ $loop->index }} ? 'bg-blue-100 text-blue-800' : ''"
                                    @class(['flex w-full justify-between px-3 py-2 text-right text-sm hover:bg-blue-50', 'bg-blue-50 font-black text-blue-700' => $deliveryCompanyId === $company->id])
                                ><span>{{ $company->name }}</span><span dir="ltr" class="text-slate-500">{{ $company->phone }}</span></button>
                            @empty
                                <p class="px-3 py-2 text-xs text-slate-500">لا توجد نتائج مطابقة.</p>
                            @endforelse
                        </div>
                    @endif
                    @error('deliveryCompanyId')<span class="mt-1 block text-rose-600">اختر شركة التوصيل.</span>@enderror
                </div>
                <button type="button" wire:click="confirmDeliveryDetails" class="mt-4 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white hover:bg-indigo-700">تأكيد والمتابعة للحفظ</button>
            </div>
        </div>
    @endif

</div>
