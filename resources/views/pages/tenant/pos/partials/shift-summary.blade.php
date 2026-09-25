<div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">

    {{-- عنوان البطاقة والحالة --}}
    <div class="flex items-center justify-between">

        <div>
            <div class="text-xs font-black text-slate-900">
                الصندوق والشيفت
            </div>

            <div class="mt-0.5 text-[10px] text-slate-400">
                الحالة مرتبطة بالمستخدم والفرع الحالي
            </div>
        </div>

        @if ($this->activeShift())
            <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-black text-emerald-700">
                ● مفتوح
            </span>
        @else
            <span class="rounded-full bg-rose-100 px-2.5 py-1 text-[10px] font-black text-rose-700">
                ● مغلق
            </span>
        @endif

    </div>


    {{-- بيانات الشيفت --}}
    @if ($this->activeShift())

        <div class="mt-3 grid grid-cols-2 gap-2">

            {{-- رقم الشيفت --}}
            <div class="rounded-xl bg-slate-50 p-2.5">

                <div class="text-[9px] font-bold text-slate-400">
                    رقم الشيفت
                </div>

                <div class="mt-1 font-mono text-sm font-black text-slate-900">
                    {{ $this->activeShift()?->id ?? '—' }}
                </div>

            </div>


            {{-- الرصيد الافتتاحي --}}
            <div class="rounded-xl bg-slate-50 p-2.5">

                <div class="text-[9px] font-bold text-slate-400">
                    الرصيد الافتتاحي
                </div>

                <div class="mt-1 font-mono text-sm font-black text-slate-900">
                    {{ number_format((float) ($this->activeShift()?->opening_cash ?? 0), 2) }}
                </div>

            </div>


            {{-- إجمالي البيع --}}
            <div class="rounded-xl bg-emerald-50 p-2.5">

                <div class="text-[9px] font-bold text-emerald-600">
                    إجمالي البيع
                </div>

                <div class="mt-1 font-mono text-sm font-black text-emerald-700">
                    {{ number_format((float) ($this->activeShift()?->live_total_sales ?? 0), 2) }}
                </div>

            </div>


            {{-- إجمالي المرتجع --}}
            <div class="rounded-xl bg-rose-50 p-2.5">

                <div class="text-[9px] font-bold text-rose-600">
                    إجمالي المرتجع
                </div>

                <div class="mt-1 font-mono text-sm font-black text-rose-700">
                    {{ number_format((float) ($this->activeShift()?->live_total_returns ?? 0), 2) }}
                </div>

            </div>


            {{-- الكاش المتوقع --}}
            <div class="col-span-2 rounded-xl border border-blue-100 bg-blue-50 p-3">

                <div class="flex items-center justify-between">

                    <div>

                        <div class="text-[9px] font-bold text-blue-600">
                            الكاش المتوقع
                        </div>

                        <div class="mt-1 text-[10px] text-blue-400">
                            الافتتاحي + المبيعات النقدية − المرتجعات النقدية
                        </div>

                    </div>

                    <div class="font-mono text-lg font-black text-blue-700">
                        {{ number_format((float) ($this->activeShift()?->live_expected_cash ?? 0), 2) }}
                    </div>

                </div>

            </div>

        </div>

    @endif

</div>
