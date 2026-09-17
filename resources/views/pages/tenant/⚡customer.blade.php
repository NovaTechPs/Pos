<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    public bool $showStatementModal = false;
    public $selectedCustomerForStatement = null;
    public array $statementTransactions = [];
    public float $finalBalance = 0.0;

    public function openStatementModal($id)
    {
        $tenantId = session('active_tenant_id') ?? auth()->user()->tenant_id;

        $this->selectedCustomerForStatement = Customer::where('tenant_id', $tenantId)->findOrFail($id);

        // 1. جلب المبيعات والمرتجعات
        $orders = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $id)
            ->select('created_at', 'order_number as ref', 'type', 'grand_total')
            ->get()
            ->map(function ($order) {
                $isReturn =$order->type === 'return';
                return [
                    'date' => $order->created_at,
                    'type' => $isReturn ? 'مرتجع مبيعات' : 'فاتورة مبيعات',
                    'ref' => $order->ref,
                    'debit' => $isReturn ? 0 : (float) $order->grand_total,
                    'credit' => $isReturn ? (float) $order->grand_total : 0,
                ];
            });

        // 2. جلب المقبوضات
        $payments = DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $id)
            ->select('created_at', 'payment_number as ref', 'amount')
            ->get()
            ->map(function ($payment) {
                return [
                    'date' => $payment->created_at,
                    'type' => 'سند قبض / دفعة',
                    'ref' => $payment->ref ?? '-',
                    'debit' => 0,
                    'credit' => (float) $payment->amount,
                ];
            });

        // 3. دمج وترتيب حسب التاريخ
        $allTransactions = $orders->concat($payments)->sortBy('date')->values();

        // 4. احتساب الرصيد التراكمي
        $runningBalance = (float)$this->selectedCustomerForStatement->opening_balance;
        $this->statementTransactions = [];

        foreach ($allTransactions as $txn) {$runningBalance += ($txn['debit'] -$txn['credit']);
            $txn['balance'] =$runningBalance;
            $this->statementTransactions[] =$txn;
        }

        $this->finalBalance =$runningBalance;
        $this->showStatementModal = true;
    }

    public function closeStatementModal()
    {
        $this->showStatementModal = false;
        $this->selectedCustomerForStatement = null;
        $this->statementTransactions = [];$this->finalBalance = 0.0;
    }
};
?>

<div x-data="statementPrinter()" class="w-full">
    <!-- مودال كشف الحساب بمخطط Flex UI -->
    @if ($showStatementModal &&$selectedCustomerForStatement)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-hidden">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl flex flex-col max-h-[90vh] border border-gray-100 overflow-hidden">

                <!-- Flex Header -->
                <div class="flex flex-row justify-between items-center p-5 border-b border-gray-100 bg-gray-50/50">
                    <div class="flex flex-col gap-1">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                            <span>📄</span> كشف حساب تفصيلي
                        </h3>
                        <p class="text-xs text-gray-500 flex items-center gap-2">
                            <span class="font-semibold text-gray-700">{{ $selectedCustomerForStatement->name }}</span>
                            <span>•</span>
                            <span>{{ $selectedCustomerForStatement->phone ?? 'بدون رقم' }}</span>
                        </p>
                    </div>
                    <button wire:click="closeStatementModal" class="flex items-center justify-center w-8 h-8 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-200 transition-colors">
                        &times;
                    </button>
                </div>

                <!-- Flex Body (Scrollable) -->
                <div class="flex flex-col gap-4 p-5 overflow-y-auto flex-1">

                    <!-- Flex Balance Summary Bar -->
                    <div class="flex flex-row justify-between items-center p-4 bg-gray-50 rounded-xl border border-gray-100">
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500 font-medium">الرصيد الافتتاحي</span>
                            <span class="text-sm font-bold text-gray-800">{{ number_format($selectedCustomerForStatement->opening_balance, 2) }}</span>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="text-xs text-gray-500 font-medium">الرصيد المتبقي النهائي</span>
                            <span class="text-base font-bold {{ $finalBalance > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                                {{ number_format($finalBalance, 2) }}
                            </span>
                        </div>
                    </div>

                    <!-- Flex Table Container -->
                    <div class="flex flex-col border border-gray-200 rounded-xl overflow-hidden">
                        <!-- Table Header -->
                        <div class="flex flex-row bg-gray-100/80 p-3 text-xs font-bold text-gray-600 border-b border-gray-200">
                            <div class="flex-1">التاريخ</div>
                            <div class="flex-1">البيان</div>
                            <div class="w-20 text-center">المرجع</div>
                            <div class="w-24 text-red-600 text-left">مدين (+)</div>
                            <div class="w-24 text-emerald-600 text-left">دائن (-)</div>
                            <div class="w-24 text-left">الرصيد</div>
                        </div>

                        <!-- Table Rows -->
                        <div class="flex flex-col divide-y divide-gray-100 text-xs">
                            <!-- Opening Balance Row -->
                            <div class="flex flex-row p-3 bg-gray-50/50 items-center">
                                <div class="flex-1 text-gray-500">{{ \Carbon\Carbon::parse($selectedCustomerForStatement->created_at)->format('Y-m-d') }}</div>
                                <div class="flex-1 font-semibold text-gray-700">رصيد افتتاحي</div>
                                <div class="w-20 text-center text-gray-400">-</div>
                                <div class="w-24 text-left font-semibold text-red-600">{{ number_format($selectedCustomerForStatement->opening_balance, 2) }}</div>
                                <div class="w-24 text-left font-semibold text-emerald-600">0.00</div>
                                <div class="w-24 text-left font-bold text-gray-800">{{ number_format($selectedCustomerForStatement->opening_balance, 2) }}</div>
                            </div>

                            <!-- Dynamic Transactions -->
                            @forelse ($statementTransactions as$txn)
                                <div class="flex flex-row p-3 items-center hover:bg-gray-50 transition-colors">
                                    <div class="flex-1 text-gray-500 whitespace-nowrap">{{ \Carbon\Carbon::parse($txn['date'])->format('Y-m-d H:i') }}</div>
                                    <div class="flex-1 font-medium text-gray-800">{{ $txn['type'] }}</div>
                                    <div class="w-20 text-center text-gray-500">{{ $txn['ref'] }}</div>
                                    <div class="w-24 text-left font-semibold text-red-600">{{ $txn['debit'] > 0 ? number_format($txn['debit'], 2) : '-' }}</div>
                                    <div class="w-24 text-left font-semibold text-emerald-600">{{ $txn['credit'] > 0 ? number_format($txn['credit'], 2) : '-' }}</div>
                                    <div class="w-24 text-left font-bold text-gray-900">{{ number_format($txn['balance'], 2) }}</div>
                                </div>
                            @empty
                                <div class="flex justify-center items-center p-6 text-gray-400">
                                    لا توجد عمليات مسجلة للعميل.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <!-- Flex Footer Actions -->
                <div class="flex flex-row justify-between items-center p-4 border-t border-gray-100 bg-gray-50">
                    <button type="button" @click="connectPrinter()" class="flex items-center gap-2 px-4 py-2 border border-gray-300 bg-white rounded-xl text-xs font-semibold text-gray-700 hover:bg-gray-100 transition-colors shadow-sm">
                        <span>🔌</span> ربط الطابعة الحرارية
                    </button>

                    <div class="flex flex-row gap-2">
                        <button type="button" wire:click="closeStatementModal" class="px-4 py-2 border border-gray-200 rounded-xl text-xs font-semibold text-gray-600 hover:bg-gray-100 transition-colors">
                            إغلاق
                        </button>
                        <button type="button" @click="printStatement({{ json_encode($selectedCustomerForStatement) }}, {{ json_encode($statementTransactions) }}, {{$finalBalance }})" class="flex items-center gap-2 px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-colors shadow-sm">
                            <span>طباعة حرارية</span> 🖨️
                        </button>
                    </div>
                </div>

            </div>
        </div>
    @endif
</div>

<script>
function statementPrinter() {
    return {
        port: null,
        async connectPrinter() {
            if ('serial' in navigator) {
                try {
                    this.port = await navigator.serial.requestPort();
                    await this.port.open({ baudRate: 9600 });
                    alert('تم الاتصال بالطابعة الحرارية بنجاح');
                } catch (err) {
                    alert('تعذر الاتصال بالطابعة: ' + err.message);
                }
            } else {
                alert('متصفحك لا يدعم خاصية Web Serial API.');
            }
        },
        async printStatement(customer, transactions, finalBalance) {
            if (!this.port) {
                const connectFirst = confirm("لم يتم ربط طابعة Web Serial بعد. هل تريد الربط الآن؟");
                if (connectFirst) {
                    await this.connectPrinter();
                }
                if (!this.port) return;
            }

            try {
                const writer = this.port.writable.getWriter();
                const esc = '\x1B';
                const init = esc + '@';
                const alignCenter = esc + 'a' + '\x01';
                const alignRight = esc + 'a' + '\x02';
                const cut = esc + 'i';

                let rawText = init + alignCenter;
                rawText += "==============================\n";
                rawText += "      ACCOUNT STATEMENT       \n";
                rawText += "==============================\n";
                rawText += alignRight;
                rawText += `Customer: ${customer.name}\n`;
                rawText += `Phone: ${customer.phone || '-'}\n`;
                rawText += `Date: ${new Date().toISOString().split('T')[0]}\n`;
                rawText += "------------------------------\n";
                rawText += `Open Bal : ${Number(customer.opening_balance).toFixed(2)}\n`;
                rawText += "------------------------------\n";

                transactions.forEach(t => {
                    const type = t.debit > 0 ? 'DR' : 'CR';
                    const amt = t.debit > 0 ? t.debit : t.credit;
                    rawText += `${t.ref} [${type}]: ${Number(amt).toFixed(2)}\n`;
                    rawText += ` Bal: ${Number(t.balance).toFixed(2)}\n`;
                    rawText += ".-.-.-.-.-.-.-.-.-.-.-.-.-.-.-\n";
                });

                rawText += "==============================\n";
                rawText += `FINAL BAL: ${Number(finalBalance).toFixed(2)}\n`;
                rawText += "==============================\n\n\n\n";
                rawText += cut;

                const encoder = new TextEncoder();
                await writer.write(encoder.encode(rawText));
                writer.releaseLock();
            } catch (e) {
                alert('خطأ أثناء الطباعة: ' + e.message);
            }
        }
    }
}
</script>
