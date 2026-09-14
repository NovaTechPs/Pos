<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    public $supplier_id;
    public $amount;
    public $payment_method = 'cash';
    public $notes;
    public $payment_date;

    public function mount()
    {
        $this->payment_date = now()->format('Y-m-d\TH:i');
    }

    public function savePayment()
    {
        $this->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,bank_transfer,cheque',
            'notes' => 'nullable|string|max:500',
            'payment_date' => 'required|date',
        ]);

        $user = auth()->user();

        $activeShift = Shift::where('tenant_id', $user->tenant_id)
            ->where('branch_id', $user->branch_id)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        $voucherNumber = 'PAY-' . strtoupper(uniqid());

        DB::transaction(function () use ($user, $activeShift, $voucherNumber) {
            Payment::create([
                'tenant_id'      => $user->tenant_id,
                'branch_id'      => $user->branch_id,
                'shift_id'       => $activeShift?->id,
                'user_id'        => $user->id,
                'type'           => 'payment',
                'voucher_number' => $voucherNumber,
                'payable_type'   => Supplier::class,
                'payable_id'     => $this->supplier_id,
                'amount'         => $this->amount,
                'payment_method' => $this->payment_method,
                'notes'          => $this->notes,
                'payment_date'   => $this->payment_date,
            ]);
        });

        session()->flash('message', 'تم حفظ سند الدفع بنجاح: ' . $voucherNumber);

        $this->reset(['amount', 'notes', 'supplier_id']);
        $this->payment_date = now()->format('Y-m-d\TH:i');
    }

    public function with()
    {
        $tenantId = auth()->user()->tenant_id;

        return [
            'suppliers' => Supplier::where('tenant_id', $tenantId)->get(),
            'payments'  => Payment::with(['payable', 'user'])
                ->where('tenant_id', $tenantId)
                ->where('type', 'payment')
                ->latest('payment_date')
                ->paginate(10),
        ];
    }
}; ?>

<div class="p-6 dir-rtl text-right">
    <div class="max-w-7xl mx-auto space-y-6">

        @if (session()->has('message'))
            <div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50 dark:bg-gray-800 dark:text-green-400">
                {{ session('message') }}
            </div>
        @endif

        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl shadow-md border border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-bold mb-4 text-gray-800 dark:text-white">إضافة سند دفع (صرف للمورد)</h2>

            <form wire:submit="savePayment" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">اختر المورد</label>
                        <select wire:model="supplier_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white">
                            <option value="">-- اختر --</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }} {{ $supplier->company_name ? "({$supplier->company_name})" : '' }}</option>
                            @endforeach
                        </select>
                        @error('supplier_id') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">المبلغ المدفوع</label>
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">البيان / ملاحظات</label>
                    <textarea wire:model="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:bg-gray-700 dark:text-white"></textarea>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-md shadow font-medium">
                        حفظ سند الدفع
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-6 rounded-xl shadow-md border border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-bold mb-4 text-gray-800 dark:text-white">سجل سندات الدفع</h3>
            <table class="w-full text-sm text-right text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">رقم السند</th>
                        <th class="px-4 py-3">المورد</th>
                        <th class="px-4 py-3">المبلغ</th>
                        <th class="px-4 py-3">طريقة الدفع</th>
                        <th class="px-4 py-3">التاريخ</th>
                        <th class="px-4 py-3">المستخدم</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($payments as $payment)
                        <tr class="border-b dark:border-gray-700">
                            <td class="px-4 py-3 font-semibold">{{ $payment->voucher_number }}</td>
                            <td class="px-4 py-3">{{ $payment->payable?->name }}</td>
                            <td class="px-4 py-3 font-bold text-rose-600">{{ number_format($payment->amount, 2) }}</td>
                            <td class="px-4 py-3">{{ $payment->payment_method }}</td>
                            <td class="px-4 py-3">{{ $payment->payment_date->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3">{{ $payment->user?->name }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4">لا توجد سندات دفع مسجلة.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $payments->links() }}</div>
        </div>

    </div>
</div>
