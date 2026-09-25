@if ($showCostModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
        <div class="w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between bg-indigo-700 p-4 text-white"><div class="text-sm font-black">تحليل التكلفة والربح</div><button wire:click="$set('showCostModal', false)">✕</button></div>
            <div class="max-h-[60vh] overflow-auto p-3">
                <table class="w-full text-right text-xs">
                    <thead class="sticky top-0 bg-slate-100"><tr><th class="p-2">الصنف</th><th class="p-2 text-center">الكمية</th><th class="p-2 text-center">السعر</th><th class="p-2 text-center">التكلفة</th><th class="p-2 text-center">إجمالي التكلفة</th><th class="p-2 text-center">الربح</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($cart as $item)
                            @php $cost = (float) ($item['cost_price'] ?? 0); $itemCost = $cost * (int) $item['quantity']; $profit = (float) $item['subtotal'] - $itemCost; @endphp
                            <tr><td class="p-2 font-black">{{ $item['name'] }}</td><td class="p-2 text-center font-mono">{{ $item['quantity'] }}</td><td class="p-2 text-center font-mono">{{ number_format($item['price'],2) }}</td><td class="p-2 text-center font-mono">{{ number_format($cost,2) }}</td><td class="p-2 text-center font-mono text-rose-700">{{ number_format($itemCost,2) }}</td><td class="p-2 text-center font-mono font-black {{ $profit >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($profit,2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 p-3 text-xs font-black"><span>التكلفة: <b class="font-mono text-rose-700">{{ number_format($this->total_cost,2) }}</b></span><span>الربح المتوقع: <b class="font-mono {{ $this->expected_profit >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($this->expected_profit,2) }}</b></span><button wire:click="$set('showCostModal', false)" class="rounded-lg bg-slate-800 px-4 py-2 text-white">إغلاق</button></div>
        </div>
    </div>
@endif
