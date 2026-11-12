@php $invoiceLocked = $this->invoiceIsLocked(); @endphp

        <div class="pos-payment-actions pos-invoice-actions grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">


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



            {{-- طباعة / حفظ وطباعة --}}

            <button
                type="button"
                x-on:click="saveAndPrint()"
                x-bind:disabled="printInProgress || {{ empty($cart) ? 'true' : 'false' }}"
                title="{{ $invoiceLocked ? 'طباعة الفاتورة القديمة' : 'حفظ الفاتورة وطباعة' }}"
                class="rounded-xl px-1.5 py-2.5 text-[9px] font-black text-white shadow-sm transition disabled:bg-slate-200 disabled:text-slate-400
                    {{
                        $isReturnMode
                            ? 'bg-rose-600 hover:bg-rose-700'
                            : 'bg-emerald-600 hover:bg-emerald-700'
                    }}"
            >

                <span class="block">
                    {{ $invoiceLocked ? 'طباعة' : 'حفظ وطباعة' }}
                </span>

                @if (!$invoiceLocked)
                    <kbd class="mt-1 inline-block rounded bg-black/20 px-1 py-0.5 text-[7px]">
                        F6
                    </kbd>
                @else
                    <span class="mt-1 block text-[7px] font-bold text-white/80">
                        الفاتورة محفوظة
                    </span>
                @endif

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



            {{-- نسخ الفاتورة --}}

            <button
                type="button"
                wire:click="copyLoadedInvoice"
                @disabled(empty($cart))
                title="نسخ الفاتورة إلى فاتورة جديدة"
                class="rounded-xl border border-violet-200 bg-violet-50 px-1.5 py-2.5 text-[9px] font-black text-violet-700 transition hover:bg-violet-100 disabled:opacity-40"
            >
                <span class="block">📋 نسخ</span>
                <span class="mt-1 block text-[7px] font-bold text-violet-600">فاتورة جديدة</span>
            </button>



            {{-- واتساب --}}

            <button
                type="button"
                x-on:click="window.__posWhatsAppWindow = window.open('about:blank', '_blank')"
                wire:click="sendInvoiceWhatsApp"
                wire:loading.attr="disabled"
                title="حفظ الفاتورة أولاً ثم إرسالها عبر واتساب"
                class="rounded-xl border border-green-200 bg-green-50 px-1.5 py-2.5 text-[9px] font-black text-green-700 transition hover:bg-green-100 disabled:opacity-50"
            >
                <span class="block">🟢 واتساب</span>
                <span class="mt-1 block text-[7px] font-bold text-green-600">حفظ وإرسال</span>
            </button>



        </div>
