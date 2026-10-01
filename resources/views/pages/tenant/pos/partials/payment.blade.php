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
            ->whereIn('type', ['customer', 'both'])
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

            $whatsappMessage .=
                "• " .
                ($item['name'] ?? 'منتج') .
                " × " .
                ($item['qty'] ?? 0) .
                " = " .
                ($item['total'] ?? 0) .
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

            $whatsappMessage .=
                "• " .
                ($item['name'] ?? 'منتج') .
                " × " .
                ($item['quantity'] ?? 0) .
                " = " .
                number_format(
                    (float) ($item['subtotal'] ?? 0),
                    2
                ) .
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
@endphp


<div
    class="rounded-2xl border border-slate-200 bg-white p-2.5 shadow-sm"
    x-data
    dir="rtl"
>

    <div class="grid grid-cols-1 gap-2 xl:grid-cols-12">


        {{-- =========================================================
             ملخص المبالغ
        ========================================================== --}}

        <div class="grid grid-cols-2 gap-2 xl:col-span-5 xl:grid-cols-5">


            {{-- الإجمالي --}}

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-2">

                <div class="text-[9px] font-black text-slate-500">
                    الإجمالي
                </div>

                <div class="mt-1 rounded-lg bg-white px-2 py-2 text-center">

                    <span class="font-mono text-base font-black text-slate-900">
                        {{ number_format($this->subtotal, 2) }}
                    </span>

                </div>

            </div>


            {{-- الخصم --}}

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-2">

                <div class="flex items-center justify-between">

                    <span class="text-[9px] font-black text-slate-500">
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


            {{-- المطلوب --}}

            <div class="rounded-xl border border-amber-200 bg-amber-50 p-2">

                <div class="text-[9px] font-black text-amber-700">

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

            <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-2">

                <div class="text-[9px] font-black text-indigo-700">

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

            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-2">

                <div
                    class="text-[9px] font-black
                    {{ $this->remaining > 0
                        ? 'text-rose-700'
                        : 'text-emerald-700' }}"
                >

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

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-2 xl:col-span-3">

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
                    ['cash', 'نقدي', '💵'],
                    ['card', 'بطاقة', '💳'],
                    ['bank_transfer', 'تحويل', '🏦'],
                    ['cheque', 'شيك', '🧾']
                ] as [$value, $label, $icon])

                    <button
                        type="button"
                        wire:click="$set('payment_method', '{{ $value }}')" @disabled($invoiceLocked)
                        class="rounded-xl border px-2 py-2.5 text-[10px] font-black transition
                            {{
                                $payment_method === $value
                                    ? 'border-indigo-600 bg-indigo-600 text-white shadow-sm'
                                    : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-300 hover:bg-indigo-50'
                            }}"
                    >

                        {{ $icon }}
                        {{ $label }}

                    </button>

                @endforeach

            </div>

        </div>



        {{-- =========================================================
             العميل
        ========================================================== --}}

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-2 xl:col-span-2">

            <div class="mb-1.5 flex items-center justify-between">

                <span class="text-[10px] font-black text-slate-800">
                    العميل
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
                    class="w-full rounded-xl border border-indigo-200 bg-indigo-50 px-2 py-2 text-right hover:bg-indigo-100"
                >

                    <div class="truncate text-xs font-black text-indigo-800">

                        {{ $selectedCustomer?->name ?? 'العميل المحدد' }}

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
                    class="w-full rounded-xl border border-indigo-200 bg-white px-3 py-2.5 text-xs font-black text-indigo-700 hover:bg-indigo-50"
                >

                    👤 إضافة زبون للفاتورة

                </button>

            @endif


            {{-- ملاحظات الفاتورة --}}

            <div class="mt-2">

                <label class="mb-1 block text-[9px] font-black text-slate-500">

                    ملاحظات

                </label>


                <input
                    wire:model.live="notes" @disabled($invoiceLocked)
                    type="text"
                    placeholder="ملاحظة الفاتورة..."
                    class="w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-[10px] font-bold outline-none focus:border-indigo-500"
                >

            </div>

        </div>



        {{-- =========================================================
             لوحة الأرقام
        ========================================================== --}}




        {{-- =========================================================
             أزرار الفاتورة
        ========================================================== --}}

        <div class="grid grid-cols-5 gap-1.5 xl:col-span-2">


            {{-- حفظ --}}

            <button
                type="button"
                wire:click="saveOrEditInvoice"
                @disabled(empty($cart) && !$invoiceLocked)
                class="rounded-xl bg-slate-900 px-1.5 py-2.5 text-[9px] font-black text-white shadow-sm transition hover:bg-slate-800 disabled:bg-slate-200 disabled:text-slate-400"
            >

                <span class="block">
                    {{ $invoiceLocked ? 'تعديل' : 'حفظ' }}
                </span>

                <kbd class="mt-1 inline-block rounded bg-slate-700 px-1 py-0.5 text-[7px]">
                    F3
                </kbd>

            </button>



            {{-- حفظ وطباعة --}}

            <button
                type="button"
                wire:click="checkoutAndPrint"
                @disabled(empty($cart) || $invoiceLocked)
                class="rounded-xl px-1.5 py-2.5 text-[9px] font-black text-white shadow-sm transition disabled:bg-slate-200 disabled:text-slate-400
                    {{
                        $isReturnMode
                            ? 'bg-rose-600 hover:bg-rose-700'
                            : 'bg-emerald-600 hover:bg-emerald-700'
                    }}"
            >

                <span class="block">
                    حفظ وطباعة
                </span>

                <kbd class="mt-1 inline-block rounded bg-black/20 px-1 py-0.5 text-[7px]">
                    F6
                </kbd>

            </button>



            {{-- فاتورة جديدة --}}

            <button
                type="button"
                wire:click="clearCart"
                @disabled(empty($cart))
                class="rounded-xl border border-slate-200 bg-slate-50 px-1.5 py-2.5 text-[9px] font-black text-slate-600 transition hover:bg-slate-100 disabled:opacity-40"
            >

                <span class="block">
                    فاتورة جديدة
                </span>

                <kbd class="mt-1 inline-block rounded bg-slate-200 px-1 py-0.5 text-[7px]">
                    F4
                </kbd>

            </button>



            {{-- PDF --}}

            <button
                type="button"
                wire:click="downloadInvoicePdf"
                class="rounded-xl border border-red-200 bg-red-50 px-1.5 py-2.5 text-[9px] font-black text-red-700 transition hover:bg-red-100"
                title="إنشاء PDF للفاتورة"
            >

                <span class="block">
                    📄 PDF
                </span>

                <span class="mt-1 block text-[7px] font-bold text-red-600">
                    فاتورة
                </span>

            </button>



            {{-- واتساب --}}

            @if ($whatsappPhone)
                <button
                    type="button"
                    data-phone="{{ $whatsappPhone }}"
                    data-message="{{ $whatsappMessage }}"
                    title="إرسال الفاتورة للعميل عبر واتساب"
                    onclick="
                        const phone = this.dataset.phone;
                        const message = this.dataset.message;

                        if (!message) {
                            alert('لا توجد بيانات فاتورة لإرسالها.');
                            return;
                        }

                        const url =
                            'https://wa.me/' +
                            phone +
                            '?text=' +
                            encodeURIComponent(message);

                        window.open(url, '_blank');
                    "
                    class="rounded-xl border border-green-200 bg-green-50 px-1.5 py-2.5 text-[9px] font-black text-green-700 transition hover:bg-green-100"
                >
                    <span class="block">🟢 واتساب</span>
                    <span class="mt-1 block text-[7px] font-bold text-green-600">إرسال الفاتورة</span>
                </button>
            @else
                <button
                    type="button"
                    wire:click="openCustomerPhoneModal"
                    @disabled(!$selectedCustomerId)
                    class="rounded-xl border border-green-200 bg-green-50 px-1.5 py-2.5 text-[9px] font-black text-green-700 transition hover:bg-green-100 disabled:cursor-not-allowed disabled:opacity-40"
                    title="إضافة رقم الهاتف أو فتح واتساب واختيار الزبون"
                >
                    <span class="block">🟢 واتساب</span>
                    <span class="mt-1 block text-[7px] font-bold text-green-600">إضافة / اختيار</span>
                </button>
            @endif


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
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-indigo-500 focus:bg-white"
                    >


                    <div class="mt-2 max-h-80 overflow-y-auto">

                        @forelse ($this->customerResults as $customer)

                            <button
                                type="button"
                                wire:click="selectCustomer({{ $customer->id }})"
                                class="mb-1 flex w-full items-center justify-between gap-3 rounded-xl border border-transparent px-3 py-3 text-right hover:border-indigo-100 hover:bg-indigo-50"
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
                            يمكنك تسجيل صفر، جزء من المبلغ، أو كامل الفاتورة
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

</div>
