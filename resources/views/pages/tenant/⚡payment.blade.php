<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Payment;
use App\Models\Party;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    // الخواص العامة للمكون
    public $paymentId = null;
    public $voucher_number;
    public $type = 'payment';
    public $payable_type = Party::class;
    public $payable_id;
    public $amount;
    public $payment_method = 'cash';
    public $notes;
    public $payment_date;
    public bool $send_whatsapp_on_save = true;

    // الرقم المعتمد القياسي لإرسال رسائل الواتساب
    public string $default_whatsapp_number = '970592700780';

    protected function rules()
    {
        return [
            'type'           => 'required|in:payment,receipt',
            'payable_type'   => 'required|string',
            'payable_id'     => 'required|integer|exists:parties,id',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,bank_transfer,cheque',
            'notes'          => 'nullable|string|max:500',
            'payment_date'   => 'required|date',
            'voucher_number' => 'nullable|string|max:50',
        ];
    }

    public function mount()
    {
        $user = auth()->user();

        if ($user && $user->can('Voucher.add')) {
            $this->type = 'payment';
        } elseif ($user && $user->can('Receipt.add')) {
            $this->type = 'receipt';
        }

        $this->payable_type = Party::class;
        $this->payment_date = now()->format('Y-m-d\TH:i');
        $this->generateVoucherNumber();
    }

    private function generateVoucherNumber()
    {
        $prefix = $this->type === 'receipt' ? 'REC-' : 'PAY-';
        $tenantId = $this->getTenantId();

        $latest = Payment::where('type', $this->type)
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->latest('id')
            ->first();

        $nextNumber = $latest ? ((int) preg_replace('/[^0-9]/', '', $latest->voucher_number)) + 1 : 1001;
        $this->voucher_number = $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    private function getTenantId(): ?int
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }
        if (!empty($user->tenant_id)) {
            return (int) $user->tenant_id;
        }
        if (method_exists($user, 'tenants')) {
            return $user->tenants()->first()?->id;
        }

        return session('active_tenant_id') ? (int) session('active_tenant_id') : null;
    }

    public function setType($newType)
    {
        if ($newType === 'payment' && !auth()->user()?->can('Voucher.add')) {
            return;
        }
        if ($newType === 'receipt' && !auth()->user()?->can('Receipt.add')) {
            return;
        }

        $this->type = $newType;
        $this->payable_id = null;
        $this->generateVoucherNumber();
        $this->resetPage();
    }

 public function savePayment()
{
    $this->validate();

    if (!$this->voucher_number) {
        $this->generateVoucherNumber();
    }

    $data = [
        'tenant_id'      => $this->getTenantId(),
        'voucher_number' => $this->voucher_number,
        'type'           => $this->type,
        'payable_type'   => $this->payable_type,
        'payable_id'     => $this->payable_id,
        'amount'         => $this->amount,
        'payment_date'   => $this->payment_date,
        'payment_method' => $this->payment_method,
        'notes'          => $this->notes,
        'user_id'        => auth()->id(),
    ];

    // حفظ سند جديد أو تعديل سند قائم بدون تمرير 'id' => null
    if ($this->paymentId) {
        $payment = Payment::findOrFail($this->paymentId);
        $payment->update($data);
    } else {
        $payment = Payment::create($data);
    }

    // 2. طباعة السند تلقائياً
    $this->printVoucher($payment->id);

    // 3. إرسال الرسالة عبر الواتساب فوراً للرقم المعتمد
    if ($this->send_whatsapp_on_save) {
        $this->sendWhatsapp($payment->id);
    }

    // 4. إعادة ضبط المدخلات
    $this->resetInputFields();
    session()->flash('message', 'تم حفظ السند بنجاح.');
}

    public function sendWhatsapp($paymentId)
    {
        $payment = Payment::with(['payable', 'user'])->findOrFail($paymentId);

        // تنظيف رقم الواتساب المعتمد
        $phone = preg_replace('/[^0-9]/', '', $this->default_whatsapp_number);

        $remainingBalance = 0;
        if ($payment->payable) {
            $party = Party::where('id', $payment->payable_id)
                ->withSum(['payments as paid_sum' => fn($q) => $q->where('type', 'payment')], 'amount')
                ->withSum(['payments as received_sum' => fn($q) => $q->where('type', 'receipt')], 'amount')
                ->withSum(['orders as orders_sum'], 'total')
                ->first();

            if ($party) {
                $openingBalance = $party->opening_balance ?? 0;
                $ordersSum     = $party->orders_sum ?? 0;
                $paidSum       = $party->paid_sum ?? 0;
                $receivedSum   = $party->received_sum ?? 0;

                if ($payment->type === 'payment') {
                    $remainingBalance = ($openingBalance + $receivedSum) - $paidSum;
                } else {
                    $remainingBalance = ($openingBalance + $ordersSum + $paidSum) - $receivedSum;
                }
            }
        }

        $storeName = auth()->user()?->tenant?->name ?? '';
        $voucherType = $payment->type === 'receipt' ? 'سند قبض' : 'سند دفع';
        $partyLabel = $payment->type === 'receipt' ? 'العميل' : 'المورد';

        $method = match ($payment->payment_method) {
            'cash'          => 'نقداً (كاش)',
            'card'          => 'بطاقة / فيزا',
            'bank_transfer' => 'تحويل بنكي',
            'cheque'        => 'شيك',
            default         => $payment->payment_method,
        };

        $msg  = "📄 *{$voucherType}*\n";
        if ($storeName) {
            $msg .= "المتجر: *{$storeName}*\n";
        }
        $msg .= "----------------------------\n";
        $msg .= "رقم السند: {$payment->voucher_number}\n";
        $msg .= "التاريخ: " . \Carbon\Carbon::parse($payment->payment_date)->format('Y-m-d H:i') . "\n";
        $msg .= "{$partyLabel}: " . ($payment->payable?->name ?? 'غير محدد') . "\n";
        $msg .= "المبلغ: *" . number_format($payment->amount, 2) . " شيكل*\n";
        $msg .= "طريقة الدفع: {$method}\n";
        $msg .= "الرصيد المتبقي: *" . number_format($remainingBalance, 2) . " شيكل*\n";
        if ($payment->notes) {
            $msg .= "البيان: {$payment->notes}\n";
        }
        $msg .= "----------------------------\n";
        $msg .= "شكراً لتعاملكم معنا 🙏";

        $whatsappUrl = "https://wa.me/{$phone}?text=" . urlencode($msg);

        $this->dispatch('open-whatsapp', url: $whatsappUrl);
    }

    public function printVoucher($paymentId)
    {
        $payment = Payment::with(['payable', 'user'])->findOrFail($paymentId);

        $remainingBalance = 0;
        if ($payment->payable) {
            $party = Party::where('id', $payment->payable_id)
                ->withSum(['payments as paid_sum' => fn($q) => $q->where('type', 'payment')], 'amount')
                ->withSum(['payments as received_sum' => fn($q) => $q->where('type', 'receipt')], 'amount')
                ->withSum(['orders as orders_sum'], 'total')
                ->first();

            if ($party) {
                $openingBalance = $party->opening_balance ?? 0;
                $ordersSum     = $party->orders_sum ?? 0;
                $paidSum       = $party->paid_sum ?? 0;
                $receivedSum   = $party->received_sum ?? 0;

                if ($payment->type === 'payment') {
                    $remainingBalance = $openingBalance + $receivedSum - $paidSum;
                } else {
                    $remainingBalance = $openingBalance + $ordersSum + $paidSum - $receivedSum;
                }
            }
        }

        $voucherData = [
            'store_name'        => auth()->user()?->tenant?->name ?? '',
            'voucher_no'        => $payment->voucher_number,
            'type'              => $payment->type === 'receipt' ? 'سند قبض' : 'سند دفع',
            'party_label'       => $payment->type === 'receipt' ? 'الزبون / العميل' : 'المورد',
            'party_name'        => $payment->payable?->name ?? 'غير محدد',
            'amount'            => number_format($payment->amount, 2),
            'remaining_balance' => number_format($remainingBalance, 2),
            'payment_method'    => match ($payment->payment_method) {
                'cash'          => 'نقداً (كاش)',
                'card'          => 'بطاقة / فيزا',
                'bank_transfer' => 'تحويل بنكي',
                'cheque'        => 'شيك',
                default         => $payment->payment_method,
            },
            'date'              => \Carbon\Carbon::parse($payment->payment_date)->format('Y-m-d H:i'),
            'user_name'         => $payment->user?->name ?? 'النظام',
            'notes'             => $payment->notes ?? '-',
        ];

        $this->dispatch('do-voucher-print', data: $voucherData);
    }

    public function resetInputFields()
    {
        $this->paymentId = null;
        $this->payable_id = null;
        $this->amount = '';
        $this->notes = '';
        $this->payment_method = 'cash';
        $this->payment_date = now()->format('Y-m-d\TH:i');
        $this->generateVoucherNumber();
    }

    public function render()
    {
        $tenantId = $this->getTenantId();
        $targetType = $this->type === 'payment' ? 'supplier' : 'customer';

        $parties = Party::when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->when(
                $targetType,
                fn($q) => $q->where(function ($query) use ($targetType) {
                    $query->where('type', $targetType)->orWhere('type', 'both');
                }),
            )
            ->withSum(['payments as paid_sum' => fn($q) => $q->where('type', 'payment')], 'amount')
            ->withSum(['payments as received_sum' => fn($q) => $q->where('type', 'receipt')], 'amount')
            ->withSum(['orders as orders_sum'], 'total')
            ->get();

        $payments = Payment::with(['payable', 'user'])
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->where('type', $this->type)
            ->latest('payment_date')
            ->paginate(10);

        return $this->view([
            'parties'  => $parties,
            'payments' => $payments,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">
    <div class="p-6 dir-rtl text-right">
        <div class="max-w-7xl mx-auto space-y-6">

            @if (session()->has('message'))
                <div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50 dark:bg-gray-800 dark:text-green-400">
                    {{ session('message') }}
                </div>
            @endif

            @if (session()->has('error'))
                <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400">
                    {{ session('error') }}
                </div>
            @endif

            @if (auth()->user()?->can('Voucher.add') || auth()->user()?->can('Receipt.add'))
                <div class="flex border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-t-xl p-2 gap-2">
                    @can('Voucher.add')
                        <button wire:click="setType('payment')" type="button"
                            class="px-6 py-2.5 rounded-lg font-bold transition-all {{ $type === 'payment' ? 'bg-rose-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' }}">
                            سندات الدفع (صرف لمورد)
                        </button>
                    @endcan

                    @can('Receipt.add')
                        <button wire:click="setType('receipt')" type="button"
                            class="px-6 py-2.5 rounded-lg font-bold transition-all {{ $type === 'receipt' ? 'bg-emerald-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' }}">
                            سندات القبض (تحصيل من عميل)
                        </button>
                    @endcan
                </div>
            @endif

            @if (auth()->user()?->can('Voucher.add') || auth()->user()?->can('Receipt.add'))
                <div class="bg-white dark:bg-gray-800 p-6 rounded-b-xl shadow-md border border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-bold mb-4 text-gray-800 dark:text-white">
                        {{ $type === 'payment' ? 'إنشاء سند دفع جديد' : 'إنشاء سند قبض جديد' }}
                    </h2>

                    <form wire:submit="savePayment" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">

                            @php
                                $formattedList = $parties
                                    ->map(function ($item) use ($type) {
                                        $openingBalance = $item->opening_balance ?? 0;
                                        $ordersSum = $item->orders_sum ?? 0;
                                        $paidSum = $item->paid_sum ?? 0;
                                        $receivedSum = $item->received_sum ?? 0;

                                        if ($type === 'payment') {
                                            $bal = $openingBalance + $receivedSum - $paidSum;
                                        } else {
                                            $bal = $openingBalance + $ordersSum + $paidSum - $receivedSum;
                                        }

                                        return [
                                            'id' => $item->id,
                                            'name' => $item->name ?? 'بدون اسم',
                                            'sub' => $item->company_name ?? ($item->phone ?? ''),
                                            'balance' => number_format($bal, 2),
                                            'raw_balance' => $bal,
                                        ];
                                    })
                                    ->values()
                                    ->toArray();
                            @endphp

                            <!-- رقم السند -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">رقم السند</label>
                                <input type="text" wire:model="voucher_number" readonly
                                    class="mt-1 block w-full rounded-md border-gray-300 bg-gray-100 dark:bg-gray-600 dark:text-white shadow-sm cursor-not-allowed">
                            </div>

                            <!-- اختيار الجهة -->
                            <div wire:key="select-party-{{ $type }}" x-data="{
                                open: false,
                                search: '',
                                selectedId: @entangle('payable_id'),
                                items: {{ json_encode($formattedList) }},
                                get filteredItems() {
                                    if (!this.search) return this.items;
                                    return this.items.filter(i =>
                                        (i.name && i.name.toLowerCase().includes(this.search.toLowerCase())) ||
                                        (i.sub && i.sub.toLowerCase().includes(this.search.toLowerCase()))
                                    );
                                },
                                get selectedItem() {
                                    return this.items.find(i => i.id == this.selectedId);
                                }
                            }" class="relative">

                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    {{ $type === 'payment' ? 'اختر/ابحث عن مورد' : 'اختر/ابحث عن عميل' }}
                                </label>

                                <button type="button" @click="open = !open"
                                    class="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm px-3 py-2 text-right cursor-pointer focus:outline-none focus:ring-1 focus:ring-indigo-500 flex justify-between items-center text-sm dark:text-white">
                                    <span x-text="selectedItem ? selectedItem.name + ' — [الرصيد: ' + selectedItem.balance + ']' : '-- اختر من القائمة --'"
                                        class="truncate"></span>
                                    <span class="mr-2 text-gray-400">▼</span>
                                </button>

                                <div x-show="open" @click.outside="open = false" x-transition
                                    class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-800 shadow-xl max-h-60 rounded-md py-2 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm border border-gray-200 dark:border-gray-700">

                                    <div class="px-2 pb-2 border-b border-gray-200 dark:border-gray-700">
                                        <input x-model="search" type="text" placeholder="اكتب للبحث..." @click.stop
                                            class="w-full text-xs p-2 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:outline-none focus:border-indigo-500">
                                    </div>

                                    <ul class="pt-1 max-h-40 overflow-y-auto">
                                        <template x-for="item in filteredItems" :key="item.id">
                                            <li @click="selectedId = item.id; open = false; search = ''"
                                                class="cursor-pointer select-none relative py-2 pr-3 pl-4 hover:bg-indigo-50 dark:hover:bg-gray-700 dark:text-white flex justify-between items-center">
                                                <div>
                                                    <span x-text="item.name" class="font-bold"></span>
                                                    <span x-text="item.sub ? ' (' + item.sub + ')' : ''"
                                                        class="text-xs text-gray-400"></span>
                                                </div>
                                                <span x-text="item.balance"
                                                    class="text-xs font-semibold px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-600"></span>
                                            </li>
                                        </template>
                                        <template x-if="filteredItems.length === 0">
                                            <li class="p-3 text-xs text-center text-gray-400">لا توجد نتائج مطابقة</li>
                                        </template>
                                    </ul>
                                </div>

                                @error('payable_id')
                                    <span class="text-red-500 text-xs">{{ $message }}</span>
                                @enderror

                                <template x-if="selectedItem">
                                    <div class="mt-2 text-xs p-2 rounded bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 flex justify-between items-center">
                                        <span class="text-gray-600 dark:text-gray-300">الرصيد المتبقي:</span>
                                        <span class="font-bold"
                                            :class="selectedItem.raw_balance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"
                                            x-text="selectedItem.balance + ' شيكل'"></span>
                                    </div>
                                </template>
                            </div>

                            <!-- المبلغ -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">المبلغ</label>
                                <input type="number" step="0.01" wire:model="amount" placeholder="0.00"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white focus:ring-indigo-500 focus:border-indigo-500">
                                @error('amount')
                                    <span class="text-red-500 text-xs">{{ $message }}</span>
                                @enderror
                            </div>

                            <!-- طريقة الدفع -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">طريقة الدفع</label>
                                <select wire:model="payment_method"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="cash">نقداً (كاش)</option>
                                    <option value="card">بطاقة / فيزا</option>
                                    <option value="bank_transfer">تحويل بنكي</option>
                                    <option value="cheque">شيك</option>
                                </select>
                                @error('payment_method')
                                    <span class="text-red-500 text-xs">{{ $message }}</span>
                                @enderror
                            </div>

                            <!-- التاريخ -->
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">التاريخ والوقت</label>
                                <input type="datetime-local" wire:model="payment_date"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white focus:ring-indigo-500 focus:border-indigo-500">
                                @error('payment_date')
                                    <span class="text-red-500 text-xs">{{ $message }}</span>
                                @enderror
                            </div>

                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">ملاحظات / البيان</label>
                            <textarea wire:model="notes" rows="2"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white focus:ring-indigo-500 focus:border-indigo-500"
                                placeholder="تفاصيل العملية..."></textarea>
                        </div>

                        <!-- خيار إرسال واتساب -->
                        <div class="flex items-center">
                            <input type="checkbox" id="send_whatsapp" wire:model="send_whatsapp_on_save"
                                class="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                            <label for="send_whatsapp" class="mr-2 block text-sm text-gray-900 dark:text-gray-100 cursor-pointer">
                                إرسال تفاصيل السند عبر WhatsApp إلى الرقم المحدد (970592700780) فور الحفظ
                            </label>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit"
                                class="px-6 py-2 {{ $type === 'payment' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' }} text-white rounded-md shadow font-medium transition">
                                {{ $type === 'payment' ? 'حفظ وطباعة سند الدفع' : 'حفظ وطباعة سند القبض' }}
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 p-6 rounded-xl shadow-md border border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold mb-4 text-gray-800 dark:text-white">
                    سجل {{ $type === 'payment' ? 'سندات الدفع' : 'سندات القبض' }}
                </h3>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-right text-gray-500 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">رقم السند</th>
                                <th class="px-4 py-3">الجهة</th>
                                <th class="px-4 py-3">المبلغ</th>
                                <th class="px-4 py-3">طريقة الدفع</th>
                                <th class="px-4 py-3">التاريخ</th>
                                <th class="px-4 py-3">المستخدم</th>
                                <th class="px-4 py-3 text-center">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($payments as $payment)
                                <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                    <td class="px-4 py-3 font-semibold">{{ $payment->voucher_number }}</td>
                                    <td class="px-4 py-3">{{ $payment->payable?->name ?? 'غير محدد' }}</td>
                                    <td class="px-4 py-3 font-bold {{ $type === 'payment' ? 'text-rose-600' : 'text-emerald-600' }}">
                                        {{ number_format($payment->amount, 2) }}
                                    </td>
                                    <td class="px-4 py-3">{{ $payment->payment_method }}</td>
                                    <td class="px-4 py-3">
                                        {{ \Carbon\Carbon::parse($payment->payment_date)->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="px-4 py-3">{{ $payment->user?->name }}</td>
                                    <td class="px-4 py-3 text-center flex justify-center gap-2">
                                        <button wire:click="printVoucher({{ $payment->id }})" type="button"
                                            class="px-3 py-1 bg-gray-700 text-white rounded text-xs hover:bg-gray-900 transition">
                                            طباعة
                                        </button>
                                        <button wire:click="sendWhatsapp({{ $payment->id }})" type="button"
                                            class="px-3 py-1 bg-emerald-600 text-white rounded text-xs hover:bg-emerald-700 transition flex items-center gap-1">
                                            واتساب
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4">لا توجد سندات مسجلة حالياً.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $payments->links() }}
                </div>
            </div>

        </div>
    </div>
</flux:main>

@script
    <script>
        $wire.on('do-voucher-print', (event) => {
            const v = event.data;

            let text = "";
            text += "--------------------------------\n";
            text += "        " + v.store_name + "        \n";
            text += "        " + v.type + "        \n";
            text += "--------------------------------\n";
            text += "رقم السند: " + v.voucher_no + "\n";
            text += "التاريخ: " + v.date + "\n";
            text += v.party_label + ": " + v.party_name + "\n";
            text += "--------------------------------\n";
            text += "المبلغ المدفوع: " + v.amount + " شيكل\n";
            text += "الرصيد المتبقي: " + v.remaining_balance + " شيكل\n";
            text += "طريقة الدفع: " + v.payment_method + "\n";
            text += "البيان: " + v.notes + "\n";
            text += "--------------------------------\n";
            text += "المستلم/الموظف: " + v.user_name + "\n";
            text += "--------------------------------\n\n\n\n";

            const intentUrl = "intent:" + encodeURIComponent(text) +
                "#Intent;" +
                "scheme=rawbt;" +
                "package=ru.a402d.rawbtprinter;" +
                "S.type=text/plain;" +
                "end;";

            window.location.href = intentUrl;
        });

        $wire.on('open-whatsapp', (event) => {
            window.open(event.url, '_blank');
        });
    </script>
@endscript
