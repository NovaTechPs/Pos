  <div class="flex-1 bg-white border border-slate-300 rounded-xl overflow-hidden shadow-sm flex flex-col min-h-0">
// d
      <!-- ==================== حقل البحث السريع المباشر فوق جدول الفاتورة ==================== -->
      <!-- ==================== حقل البحث ومسح الباركود المباشر فوق جدول الفاتورة ==================== -->
      <div class="p-2 bg-slate-50 border-b border-slate-200 relative shrink-0">
          <div class="relative flex items-center gap-2">
              <!-- حقل مسح الباركود التلقائي -->
              <!-- حقل مسح الباركود التلقائي والسريع -->
              <input type="text" wire:model="barcode" wire:keydown.enter.prevent="scanBarcode"
                  placeholder="{{ $isReturnMode ? 'امسح الباركود لإرجاعه... 🔄' : 'امسح الباركود هنا للإضافة المباشرة... 📦' }}"
                  autofocus
                  class="w-full bg-white border {{ $isReturnMode ? 'border-rose-400 focus:outline-rose-600' : 'border-indigo-300 focus:outline-indigo-600' }} rounded-lg py-1.5 px-3 text-xs font-bold text-slate-800 placeholder-slate-400 shadow-sm">
              @if (!empty($barcode))
                  <button type="button" wire:click="$set('barcode', '')"
                      class="absolute left-2.5 top-2 text-slate-400 hover:text-rose-600 font-bold text-xs">
                      ✕
                  </button>
              @endif
          </div>
      </div>

      <div class="overflow-y-auto flex-1">
          <table class="w-full text-right text-xs">
              <thead class="bg-slate-200 sticky top-0 font-bold text-slate-800 border-b border-slate-300">
                  <tr>
                      <th class="p-2">اسم المنتج</th>
                      <th class="p-2 text-center">الكمية</th>
                      <th class="p-2 text-center">التكلفة</th>
                      <th class="p-2 text-center">سعر البيع</th>
                      <th class="p-2 text-center">الإجمالي</th>
                      <th class="p-2 text-center">حذف</th>
                  </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                  @forelse($cart as $item)
                      @php
                          $isBelowCost =
                              $item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0);
                      @endphp
                      <tr class="{{ $item['quantity'] < 0 ? 'bg-rose-50/80 hover:bg-rose-100' : ($isBelowCost ? 'bg-rose-100/50 hover:bg-rose-100' : 'hover:bg-indigo-50/50') }}"
                          wire:key="cart-item-{{ $item['id'] }}">
                          <td class="p-2 font-bold text-slate-900">
                              {{ $item['name'] }}
                              @if ($item['quantity'] < 0)
                                  <span
                                      class="inline-block bg-rose-200 text-rose-800 text-[10px] px-1.5 py-0.5 rounded font-bold mr-1">مرتجع</span>
                              @elseif ($isBelowCost)
                                  <span
                                      class="inline-block bg-rose-600 text-white text-[10px] px-1.5 py-0.5 rounded font-bold mr-1 animate-bounce">تحت
                                      التكلفة</span>
                              @endif
                          </td>
                          <td class="p-2 text-center">
                              <div
                                  class="inline-flex items-center gap-1 border border-slate-300 rounded-md bg-slate-50 px-1">
                                  <button wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] - 1 }})"
                                      class="px-1.5 font-bold text-rose-600 hover:bg-slate-200 rounded">-</button>
                                  <span
                                      class="font-bold px-1 font-mono text-xs {{ $item['quantity'] < 0 ? 'text-rose-600' : '' }}">{{ $item['quantity'] }}</span>
                                  <button wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] + 1 }})"
                                      class="px-1.5 font-bold text-emerald-600 hover:bg-slate-200 rounded">+</button>
                              </div>
                          </td>
                          <td class="p-2 text-center">
                              <input type="number" step="0.01" value="{{ $item['cost_price'] ?? 0 }}"
                                  wire:change="updateCostPrice({{ $item['id'] }}, $event.target.value)"
                                  class="w-20 text-center font-mono font-bold bg-rose-50/50 border border-rose-200 text-rose-700 rounded p-1 text-xs focus:bg-white focus:outline-rose-600">
                          </td>
                          <td class="p-2 text-center">
                              <input type="number" step="0.01" value="{{ $item['price'] }}"
                                  wire:change="updateUnitPrice({{ $item['id'] }}, $event.target.value)"
                                  class="w-20 text-center font-mono font-bold {{ $isBelowCost ? 'bg-rose-200 text-rose-900 border-rose-500' : 'bg-slate-50 border-slate-300' }} border rounded p-1 text-xs focus:bg-white focus:outline-indigo-600">
                          </td>
                          <td
                              class="p-2 text-center font-mono font-black {{ $item['subtotal'] < 0 ? 'text-rose-600' : 'text-indigo-700' }}">
                              {{ number_format($item['subtotal'], 2) }}
                          </td>
                          <td class="p-2 text-center">
                              <button wire:click="removeFromCart({{ $item['id'] }})"
                                  class="text-rose-500 hover:text-rose-700 font-bold text-sm">×</button>
                          </td>
                      </tr>
                  @empty
                      <tr>
                          <td colspan="6" class="py-20 text-center text-slate-400 font-semibold">
                              الفاتورة فارغة.. اختر منتجات من القائمة أو امسح الباركود
                          </td>
                      </tr>
                  @endforelse
              </tbody>
          </table>
      </div>
      <div class="bg-slate-100 border-t border-slate-300 p-2 text-xs space-y-2">
          <div class="grid grid-cols-12 gap-2 items-center">
              <div class="col-span-6 flex items-center gap-1">
                  <span class="font-bold text-slate-700 whitespace-nowrap">الخصم:</span>
                  <div class="relative flex-1 flex items-center">
                      <input type="number" step="0.01" wire:model.live.debounce.300ms="discount_amount"
                          placeholder="{{ $discount_type === 'percentage' ? '%' : '' }}"
                          class="w-full bg-white border border-slate-300 rounded p-1 pl-7 font-mono font-bold text-slate-800 focus:outline-indigo-600">
                      @if ($discount_type === 'percentage')
                          <span class="absolute left-2 text-slate-400 font-bold text-xs">%</span>
                      @endif
                  </div>
                  <button type="button" wire:click="toggleDiscountType"
                      class="px-2 py-1 rounded font-bold text-xs border transition-colors {{ $discount_type === 'percentage' ? 'bg-indigo-600 text-white border-indigo-700' : 'bg-slate-200 text-slate-800 border-slate-300 hover:bg-slate-300' }}">
                      {{ $discount_type === 'percentage' ? '%' : 'مبلغ' }}
                  </button>
              </div>
              <div class="col-span-6 flex items-center gap-1">
                  <span class="font-bold text-slate-700 whitespace-nowrap">الإجمالي المطلوب:</span>
                  <input type="number" step="0.01" wire:model.live.debounce.300ms="custom_final_total"
                      value="{{ empty($cart) ? '0.00' : $custom_final_total }}"
                      placeholder="{{ number_format($this->total, 2) }}"
                      class="w-full bg-white border border-slate-300 rounded p-1 font-mono font-black text-indigo-700">
              </div>

              <div class="col-span-12 flex items-center gap-1">
                  <span class="font-bold text-slate-700 whitespace-nowrap">ملاحظات:</span>
                  <input type="text" wire:model.live="notes" placeholder="أضف أي ملاحظات إضافية للفاتورة..."
                      class="w-full bg-white border border-slate-300 rounded p-1 text-xs font-semibold text-slate-800 focus:outline-indigo-600">
              </div>
          </div>
      </div>
      <div class="bg-slate-900 text-white p-2.5 flex items-center justify-between text-xs font-bold">
          <div>
              <span>المجموع: </span><span
                  class="font-mono text-slate-300 mr-1">{{ number_format($this->subtotal, 2) }}</span>
              @if ($this->calculated_discount > 0)
                  <span class="text-rose-400 mr-2">(خصم:
                      {{ number_format($this->calculated_discount, 2) }})</span>
              @endif
          </div>
          <div>
              <span>{{ $this->total < 0 ? 'المسترد للزبون:' : 'المطلوب:' }}</span>
              <span class="{{ $this->total < 0 ? 'text-rose-400' : 'text-amber-400' }} font-mono text-lg mr-1">
                  {{ number_format(abs($this->total), 2) }}
              </span>
          </div>
          <div>المتبقي: <span
                  class="text-emerald-400 font-mono text-lg mr-1">{{ number_format($this->change, 2) }}</span>
          </div>
      </div>
  </div>
