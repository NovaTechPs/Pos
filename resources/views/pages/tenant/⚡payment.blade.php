<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    use WithPagination;

    public $type = 'payment'; // payment (دفع) أو receipt (قبض)
    public $payable_type = 'supplier';
    public $payable_id;
    public $amount;
    public $payment_method = 'cash';
    public $notes;
    public $payment_date;

    protected function rules()
    {
        return [
            'type'           => 'required|in:payment,receipt',
            'payable_type'   => 'required|in:supplier,customer',
            'payable_id'     => 'required|integer',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,bank_transfer,cheque',
            'notes'          => 'nullable|string|max:500',
            'payment_date'   => 'required|date',
        ];
    }

    public function mount()
    {
        $this->payment_date = now()->format('Y-m-d\TH:i');
    }

    public function setType($newType)
    {
        $this->type = $newType;
        $this->payable_type = ($newType === 'payment') ? 'supplier' : 'customer';
        $this->payable_id = null;
        $this->resetPage();
    }

    public function updatedType($value)
    {
        $this->payable_type = ($value === 'payment') ? 'supplier' : 'customer';
        $this->payable_id = null;
    }

    public function savePayment()
    {
        $this->validate();

        $user = auth()->user();

        $activeShift = Shift::where('tenant_id', $user->tenant_id)
            ->where('branch_id', $user->branch_id)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        $payableModel = $this->payable_type === 'supplier' ? Supplier::class : Customer::class;
        $prefix = $this->type === 'payment' ? 'PAY-' : 'RCV-';
        $voucherNumber = $prefix . strtoupper(uniqid());

        $payment = null;

        DB::transaction(function () use ($user, $activeShift, $payableModel, $voucherNumber, &$payment) {
            $payment = Payment::create([
                'tenant_id'      => $user->tenant_id,
                'branch_id'      => $user->branch_id,
                'shift_id'       => $activeShift?->id,
                'user_id'        => $user->id,
                'type'           => $this->type,
                'voucher_number' => $voucherNumber,
                'payable_type'   => $payableModel,
                'payable_id'     => $this->payable_id,
                'amount'         => $this->amount,
                'payment_method' => $this->payment_method,
                'notes'          => $this->notes,
                'payment_date'   => $this->payment_date,
            ]);
        });

        session()->flash('message', 'تم حفظ السند بنجاح برقم: ' . $voucherNumber);

        // تجهيز بيانات الطباعة المباشرة RawBT
        $this->printVoucher($payment->id);

        $this->reset(['amount', 'notes', 'payable_id']);
        $this->payment_date = now()->format('Y-m-d\TH:i');
    }

    public function printVoucher($paymentId)
    {
        $payment = Payment::with(['payable', 'user'])->findOrFail($paymentId);

        $voucherData = [
            'store_name'     => auth()->user()->tenant->name ?? 'المتجر',
            'voucher_no'     => $payment->voucher_number,
            'type'           => $payment->type === 'receipt' ? 'سند قبض' : 'سند دفع',
            'party_name'     => $payment->payable?->name ?? 'غير محدد',
            'amount'         => number_format($payment->amount, 2),
            'payment_method' => match($payment->payment_method) {
                'cash'          => 'نقداً (كاش)',
                'card'          => 'بطاقة / فيزا',
                'bank_transfer' => 'تحويل بنكي',
                'cheque'        => 'شيك',
                default         => $payment->payment_method,
            },
            'date'           => $payment->payment_date->format('Y-m-d H:i'),
            'user_name'      => $payment->user?->name ?? 'النظام',
            'notes'          => $payment->notes ?? '-',
        ];

        $this->dispatch('do-voucher-print', data: $voucherData);
    }

    public function render()
    {
        $tenantId = auth()->user()->tenant_id;


          return $this->view([
           'suppliers' => Supplier::where('tenant_id', $tenantId)->get(),
            'customers' => Customer::where('tenant_id', $tenantId)->get(),
            'payments'  => Payment::with(['payable', 'user'])
                ->where('tenant_id', $tenantId)
                ->where('type', $this->type)
                ->latest('payment_date')
                ->paginate(10),
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

        <!-- أزرار التنقل (Tabs) -->
        <div class="flex border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 rounded-t-xl p-2 gap-2">
            <button wire:click="setType('payment')"
                class="px-6 py-2.5 rounded-lg font-bold transition-all {{ $type === 'payment' ? 'bg-rose-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' }}">
                سندات الدفع (صرف لمورد)
            </button>
            <button wire:click="setType('receipt')"
                class="px-6 py-2.5 rounded-lg font-bold transition-all {{ $type === 'receipt' ? 'bg-emerald-600 text-white shadow-md' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' }}">
                سندات القبض (تحصيل من عميل)
            </button>
        </div>

        <!-- نموذج الإضافة -->
        <div class="bg-white dark:bg-gray-800 p-6 rounded-b-xl shadow-md border border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-bold mb-4 text-gray-800 dark:text-white">
                {{ $type === 'payment' ? 'إنشاء سند دفع جديد' : 'إنشاء سند قبض جديد' }}
            </h2>

            <form wire:submit="savePayment" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ $type === 'payment' ? 'اختر المورد' : 'اختر العميل' }}
                        </label>
                        <select wire:model="payable_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white">
                            <option value="">-- اختر --</option>
                            @if($type === 'payment')
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }} {{ $supplier->company_name ? "({$supplier->company_name})" : '' }}</option>
                                @endforeach
                            @else
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }} ({{ $customer->phone }})</option>
                                @endforeach
                            @endif
                        </select>
                        @error('payable_id') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">المبلغ</label>
                        <input type="number" step="0.01" wire:model="amount" placeholder="0.00" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white">
                        @error('amount') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">طريقة الدفع</label>
                        <select wire:model="payment_method" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white">
                            <option value="cash">نقداً (كاش)</option>
                            <option value="card">بطاقة / فيزا</option>
                            <option value="bank_transfer">تحويل بنكي</option>
                            <option value="cheque">شيك</option>
                        </select>
                        @error('payment_method') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">التاريخ والوقت</label>
                        <input type="datetime-local" wire:model="payment_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white">
                        @error('payment_date') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">ملاحظات / البيان</label>
                    <textarea wire:model="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white" placeholder="تفاصيل العملية..."></textarea>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 {{ $type === 'payment' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' }} text-white rounded-md shadow font-medium">
                        {{ $type === 'payment' ? 'حفظ وطباعة سند الدفع' : 'حفظ وطباعة سند القبض' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- جدول السجلات -->
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
                                <td class="px-4 py-3">{{ $payment->payment_date->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">{{ $payment->user?->name }}</td>
                                <td class="px-4 py-3 text-center">
                                    <button wire:click="printVoucher({{ $payment->id }})" class="px-3 py-1 bg-gray-700 text-white rounded text-xs hover:bg-gray-900">
                                        طباعة
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
        text += "الجهة: " + v.party_name + "\n";
        text += "--------------------------------\n";
        text += "المبلغ: " + v.amount + " شيكل\n";
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
</script>
@endscript
