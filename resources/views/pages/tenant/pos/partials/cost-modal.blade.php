  @if ($showCostModal)
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl overflow-hidden border border-slate-300">
                    <div class="bg-indigo-900 text-white p-3 flex justify-between items-center">
                        <h3 class="text-sm font-bold flex items-center gap-1.5">
                            <span>🔍</span>
                            <span>معاينة تكلفة وأرباح الفاتورة</span>
                        </h3>
                        <button wire:click="$set('showCostModal', false)"
                            class="text-slate-300 hover:text-white font-bold text-sm">✕</button>
                    </div>
                    <div class="p-3 max-h-96 overflow-y-auto">
                        <table class="w-full text-right text-xs">
                            <thead class="bg-slate-100 font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-2">الصنف</th>
                                    <th class="p-2 text-center">الكمية</th>
                                    <th class="p-2 text-center">سعر البيع</th>
                                    <th class="p-2 text-center">تكلفة الوحدة</th>
                                    <th class="p-2 text-center">إجمالي التكلفة</th>
                                    <th class="p-2 text-center">الربح المتوقع</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($cart as $id => $item)
                                    @php
                                        $itemCost = (float) ($item['cost_price'] ?? 0);
                                        $totalItemCost = $itemCost * $item['quantity'];
                                        $itemProfit = $item['subtotal'] - $totalItemCost;
                                    @endphp
                                    <tr>
                                        <td class="p-2 font-bold text-slate-800">{{ $item['name'] }}</td>
                                        <td class="p-2 text-center font-mono">{{ $item['quantity'] }}</td>
                                        <td class="p-2 text-center font-mono text-slate-700">
                                            {{ number_format($item['price'], 2) }}</td>
                                        <td class="p-2 text-center font-mono text-rose-600 font-semibold">
                                            {{ number_format($itemCost, 2) }}</td>
                                        <td class="p-2 text-center font-mono font-bold text-rose-700">
                                            {{ number_format($totalItemCost, 2) }}</td>
                                        <td
                                            class="p-2 text-center font-mono font-bold {{ $itemProfit >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                            {{ number_format($itemProfit, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="bg-slate-100 p-3 border-t border-slate-200 flex justify-between items-center text-xs">
                        <div class="flex gap-4 font-bold">
                            <div>
                                <span class="text-slate-600">إجمالي التكلفة:</span>
                                <span
                                    class="font-mono text-rose-700 font-black text-sm mr-1">{{ number_format($this->total_cost, 2) }}</span>
                            </div>
                            <div>
                                <span class="text-slate-600">إجمالي الربح:</span>
                                <span
                                    class="font-mono {{ $this->expected_profit >= 0 ? 'text-emerald-600' : 'text-rose-600' }} font-black text-sm mr-1">
                                    {{ number_format($this->expected_profit, 2) }}
                                </span>
                            </div>
                        </div>
                        <button wire:click="$set('showCostModal', false)"
                            class="px-4 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold rounded shadow transition-all">
                            إغلاق
                        </button>
                    </div>
                </div>
            </div>
        @endif
