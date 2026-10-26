@if ($showPriceHistory)
    @php($history = $this->productPriceHistory)
    <flux:modal wire:model="showPriceHistory" class="w-full md:w-[64rem]" name="product-price-history">
        <div dir="rtl" class="space-y-5">
            <flux:heading size="lg">سجل الأسعار — {{ $history['product']->name }}</flux:heading>
            <div class="rounded-xl bg-slate-50 p-3 font-bold text-slate-800">التكلفة الحالية: {{ number_format((float) $history['product']->cost_price, 2) }}</div>
            <label class="block text-sm font-bold">الزبون
                <select wire:model.live="priceHistoryCustomerId" class="mt-2 w-full rounded-lg border border-slate-200 p-2">
                    <option value="">اختر الزبون لعرض أسعاره</option>
                    @foreach ($history['customers'] as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>
            </label>
            <div wire:loading class="text-sm text-indigo-600">جارٍ تحميل الأسعار...</div>
            @foreach (['costs' => 'أسعار التكلفة السابقة', 'customerSales' => 'أسعار البيع لهذا الزبون', 'sales' => 'أسعار البيع لكل الزبائن'] as $section => $title)
                <section wire:key="price-section-{{ $section }}" class="space-y-2">
                    <h3 class="font-bold text-slate-900">{{ $title }}</h3>
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-right text-sm">
                            <thead class="bg-slate-50"><tr><th class="p-2">التاريخ</th><th class="p-2">الفاتورة</th><th class="p-2">{{ $section === 'costs' ? 'المورّد' : 'الزبون' }}</th><th class="p-2">الكمية</th><th class="p-2">سعر الوحدة قبل الخصم والضريبة</th></tr></thead>
                            <tbody>
                                @forelse (($history[$section] ?? []) as $row)
                                    <tr wire:key="price-{{ $section }}-{{ $row->id }}" class="border-t border-slate-100"><td class="p-2">{{ substr($row->date, 0, 10) }}</td><td class="p-2">{{ $row->invoice }}</td><td class="p-2">{{ $row->party ?: 'زبون نقدي' }}</td><td class="p-2">{{ number_format((float) $row->quantity, 2) }}</td><td class="p-2 font-bold">{{ number_format((float) $row->price, 2) }}</td></tr>
                                @empty
                                    <tr><td colspan="5" class="p-4 text-center text-slate-500">{{ $section === 'customerSales' && !$priceHistoryCustomerId ? 'اختر زبوناً لعرض سجل أسعاره.' : 'لا توجد أسعار سابقة لهذا الصنف.' }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($history[$section])
                        {{ $history[$section]->links() }}
                    @endif
                </section>
            @endforeach
            <flux:button wire:click="$set('showPriceHistory', false)">إغلاق</flux:button>
        </div>
    </flux:modal>
@endif
