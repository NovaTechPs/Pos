<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Party;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public bool $showModal = false;
    public ?int $partyId = null;

    // البيانات الأساسية للطرف
    public string $name = '';
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $address = null;
    public ?string $tax_number = null;
    public string $type = 'customer';
    public float $opening_balance = 0.0;

    // حالة كشف الحساب
    public bool $showStatementModal = false;
    public ?Party $selectedPartyForStatement = null;

    // الرقم القياسي المعتمد للواتساب
    public string $default_whatsapp_number = '970592700780';

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:50',
            'type' => 'required|in:customer,supplier,both',
            'opening_balance' => 'nullable|numeric',
        ];
    }

    private function getTenantId(): ?int
    {
        $user = auth()->user();
        if (!$user) return null;
        if (!empty($user->tenant_id)) return (int) $user->tenant_id;
        if (method_exists($user, 'tenants')) return $user->tenants()->first()?->id;
        return session('active_tenant_id') ? (int) session('active_tenant_id') : null;
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function openCreateModal()
    {
        $this->resetValidation();
        $this->resetForm();
        $this->showModal = true;
    }

    public function editParty($id)
    {
        $this->resetValidation();
        $tenantId = $this->getTenantId();

        $party = Party::where('tenant_id', $tenantId)->findOrFail($id);

        $this->partyId = $party->id;
        $this->name = $party->name;
        $this->phone = $party->phone;
        $this->email = $party->email;
        $this->address = $party->address;
        $this->tax_number = $party->tax_number;
        $this->type = $party->type;
        $this->opening_balance = (float) $party->opening_balance;

        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset(['name', 'phone', 'email', 'address', 'tax_number', 'type', 'opening_balance', 'partyId']);
        $this->type = 'customer';
        $this->opening_balance = 0.0;
    }

    public function openStatementModal($id)
    {
        $tenantId = $this->getTenantId();

        $this->selectedPartyForStatement = Party::where('tenant_id', $tenantId)
            ->with([
                'orders' => function ($query) {
                    $query->select('id', 'customer_id', 'total', 'created_at');
                },
                'payments' => function ($query) {
                    $query->select('id', 'payable_id', 'payable_type', 'amount', 'created_at', 'notes');
                },
            ])
            ->findOrFail($id);

        $this->showStatementModal = true;
    }

    public function closeStatementModal()
    {
        $this->showStatementModal = false;
        $this->selectedPartyForStatement = null;
    }

    public function save()
    {
        $validated = $this->validate();
        $tenantId = $this->getTenantId();

        if ($this->partyId) {
            $party = Party::where('tenant_id', $tenantId)->findOrFail($this->partyId);
            $party->update($validated);
            session()->flash('message', 'تم تعديل البيانات بنجاح!');
        } else {
            $validated['tenant_id'] = $tenantId;
            Party::create($validated);
            session()->flash('message', 'تمت إضافة العميل/الطرف بنجاح!');
        }

        $this->closeModal();
    }

    public function deleteParty($id)
    {
        $tenantId = $this->getTenantId();
        $party = Party::where('tenant_id', $tenantId)->find($id);

        if ($party) {
            $party->delete();
            session()->flash('message', 'تم حذف الطرف بنجاح.');
        }
    }

    // إرسال كشف الحساب عبر الواتساب
    public function sendStatementWhatsapp($partyId)
    {
        $tenantId = $this->getTenantId();
        $party = Party::where('tenant_id', $tenantId)
            ->with(['orders', 'payments'])
            ->findOrFail($partyId);

        $phone = preg_replace('/[^0-9]/', '', $this->default_whatsapp_number);
        $storeName = auth()->user()?->tenant?->name ?? '';

        $transactions = collect();
        foreach ($party->orders as $order) {
            $transactions->push([
                'date' => $order->created_at,
                'description' => 'فاتورة مبيعات #' . $order->id,
                'debit' => (float) $order->total,
                'credit' => 0.00,
            ]);
        }
        foreach ($party->payments as $payment) {
            $transactions->push([
                'date' => $payment->created_at,
                'description' => 'سداد دفعة ' . ($payment->notes ? '(' . $payment->notes . ')' : ''),
                'debit' => 0.00,
                'credit' => (float) $payment->amount,
            ]);
        }

        $sorted = $transactions->sortBy('date');
        $balance = (float) $party->opening_balance;

        $msg  = "📜 *كشف حساب*\n";
        if ($storeName) $msg .= "المتجر: *{$storeName}*\n";
        $msg .= "----------------------------\n";
        $msg .= "العميل: {$party->name}\n";
        $msg .= "رقم الهاتف: " . ($party->phone ?? '-') . "\n";
        $msg .= "التاريخ: " . date('Y-m-d H:i') . "\n";
        $msg .= "----------------------------\n";
        $msg .= "الرصيد الافتتاحي: " . number_format($party->opening_balance, 2) . " شيكل\n";

        foreach ($sorted as $t) {
            $balance += ($t['debit'] - $t['credit']);
            $date = \Carbon\Carbon::parse($t['date'])->format('Y-m-d');
            $msg .= "• {$date} | {$t['description']} | مدين: {$t['debit']} | دائن: {$t['credit']}\n";
        }

        $msg .= "----------------------------\n";
        $msg .= "الرصيد المتبقي المستحق: *" . number_format($balance, 2) . " شيكل*\n";
        $msg .= "شكراً لتعاملكم معنا 🙏";

        $whatsappUrl = "https://wa.me/{$phone}?text=" . urlencode($msg);

        $this->dispatch('open-whatsapp', url: $whatsappUrl);
    }

    // طباعة كشف الحساب حرارياً باستخدام RawBT
    public function printStatementThermal($partyId)
    {
        $tenantId = $this->getTenantId();
        $party = Party::where('tenant_id', $tenantId)
            ->with(['orders', 'payments'])
            ->findOrFail($partyId);

        $transactions = collect();
        foreach ($party->orders as $order) {
            $transactions->push([
                'date' => $order->created_at,
                'description' => 'فاتورة #' . $order->id,
                'debit' => (float) $order->total,
                'credit' => 0.00,
            ]);
        }
        foreach ($party->payments as $payment) {
            $transactions->push([
                'date' => $payment->created_at,
                'description' => 'دفعة سداد',
                'debit' => 0.00,
                'credit' => (float) $payment->amount,
            ]);
        }

        $sorted = $transactions->sortBy('date');
        $balance = (float) $party->opening_balance;

        $itemsFormatted = [];
        foreach ($sorted as $t) {
            $balance += ($t['debit'] - $t['credit']);
            $itemsFormatted[] = [
                'date' => \Carbon\Carbon::parse($t['date'])->format('Y-m-d'),
                'desc' => $t['description'],
                'debit' => number_format($t['debit'], 2),
                'credit' => number_format($t['credit'], 2),
                'bal' => number_format($balance, 2),
            ];
        }

        $statementData = [
            'store_name'      => auth()->user()?->tenant?->name ?? '',
            'party_name'      => $party->name,
            'party_phone'     => $party->phone ?? '-',
            'opening_balance' => number_format($party->opening_balance, 2),
            'final_balance'   => number_format($balance, 2),
            'date'            => date('Y-m-d H:i'),
            'items'           => $itemsFormatted,
        ];

        $this->dispatch('do-statement-print', data: $statementData);
    }

    public function render()
    {
        $tenantId = $this->getTenantId();

        $customers = Party::where('tenant_id', $tenantId)
            ->where(function ($query) {
                $query
                    ->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('phone', 'like', '%' . $this->search . '%')
                    ->orWhere('address', 'like', '%' . $this->search . '%');
            })
            ->latest()
            ->paginate($this->perPage);

        return $this->view([
            'customers' => $customers,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">
    <div class="p-3 sm:p-6 bg-gray-50 dark:bg-gray-900 min-h-screen space-y-4 sm:space-y-6" dir="rtl">

        <!-- الهيدر العلوي -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2 sm:mb-6">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-white">إدارة العملاء</h1>
                <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-1">عرض وإدارة العملاء والأطراف التجارية</p>
            </div>

            <button wire:click="openCreateModal"
                class="w-full sm:w-auto justify-center bg-gray-900 hover:bg-black dark:bg-gray-700 dark:hover:bg-gray-600 text-white px-4 py-2.5 rounded-lg text-sm font-medium flex items-center gap-2 shadow-sm transition">
                <span>إضافة عميل جديد</span>
                <span class="text-lg leading-none">+</span>
            </button>
        </div>

        <!-- رسائل التنبيه -->
        @if (session()->has('message'))
            <div class="p-4 text-sm text-green-800 bg-green-100 rounded-lg border border-green-200 dark:bg-gray-800 dark:text-green-400 dark:border-green-800">
                {{ session('message') }}
            </div>
        @endif

        <!-- شريط البحث والتصفح -->
        <div class="bg-white dark:bg-gray-800 p-3 sm:p-4 rounded-xl shadow-sm flex flex-col sm:flex-row gap-3 items-center justify-between border border-gray-100 dark:border-gray-700">
            <div class="relative w-full sm:w-1/2 md:w-1/3">
                <input type="text" wire:model.live.debounce.300ms="search"
                    placeholder="ابحث بالاسم، الهاتف أو العنوان..."
                    class="w-full pl-4 pr-10 py-2 border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-gray-200">
                <span class="absolute right-3 top-2.5 text-gray-400">🔍</span>
            </div>

            <select wire:model.live="perPage"
                class="w-full sm:w-auto border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg text-sm p-2 text-gray-600 bg-white">
                <option value="10">10 لكل صفحة</option>
                <option value="25">25 لكل صفحة</option>
                <option value="50">50 لكل صفحة</option>
            </select>
        </div>

        <!-- جدول عرض العملاء -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-x-auto">
            <table class="w-full text-right border-collapse min-w-[650px] dark:text-gray-300">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-700 border-b border-gray-100 dark:border-gray-600 text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        <th class="p-3 sm:p-4">#</th>
                        <th class="p-3 sm:p-4">الاسم</th>
                        <th class="p-3 sm:p-4">النوع</th>
                        <th class="p-3 sm:p-4">رقم الهاتف</th>
                        <th class="p-3 sm:p-4 hidden sm:table-cell">الموقع / العنوان</th>
                        <th class="p-3 sm:p-4">الرصيد الافتتاحي</th>
                        <th class="p-3 sm:p-4 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700 text-xs sm:text-sm">
                    @forelse ($customers as $customer)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/50 transition">
                            <td class="p-3 sm:p-4 text-gray-400">{{ $loop->iteration }}</td>
                            <td class="p-3 sm:p-4 font-semibold text-gray-800 dark:text-white whitespace-nowrap">{{ $customer->name }}</td>
                            <td class="p-3 sm:p-4 whitespace-nowrap">
                                @if ($customer->type === 'customer')
                                    <span class="bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 px-2 py-1 rounded-md text-xs font-medium">زبون</span>
                                @elseif($customer->type === 'supplier')
                                    <span class="bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 px-2 py-1 rounded-md text-xs font-medium">مورد</span>
                                @else
                                    <span class="bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 px-2 py-1 rounded-md text-xs font-medium">زبون ومورد</span>
                                @endif
                            </td>
                            <td class="p-3 sm:p-4 text-gray-600 dark:text-gray-300 whitespace-nowrap" dir="ltr">{{ $customer->phone ?? '-' }}</td>
                            <td class="p-3 sm:p-4 text-gray-700 dark:text-gray-300 font-medium hidden sm:table-cell">{{ $customer->address ?? '-' }}</td>
                            <td class="p-3 sm:p-4 font-medium text-gray-800 dark:text-white whitespace-nowrap">{{ number_format($customer->opening_balance, 2) }}</td>
                            <td class="p-3 sm:p-4 text-center whitespace-nowrap">
                                <div class="flex justify-center items-center gap-2 sm:gap-3">
                                    <button wire:click="openStatementModal({{ $customer->id }})"
                                        class="p-1 text-gray-400 hover:text-emerald-600 transition text-base"
                                        title="كشف حساب">📜</button>
                                    <button wire:click="editParty({{ $customer->id }})"
                                        class="p-1 text-gray-400 hover:text-blue-600 transition text-base"
                                        title="تعديل">✏️</button>
                                    <button wire:click="deleteParty({{ $customer->id }})"
                                        wire:confirm="هل أنت متأكد من حذف هذا الطرف؟"
                                        class="p-1 text-gray-400 hover:text-red-600 transition text-base"
                                        title="حذف">🗑️</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-8 text-center text-gray-400">لا توجد نتائج مطابقة للبحث.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $customers->links() }}
        </div>

        <!-- نافذة إضافة / تعديل طرف -->
        @if ($showModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-xl my-auto overflow-hidden border border-gray-100 dark:border-gray-700 max-h-[90vh] flex flex-col">
                    <div class="flex justify-between items-center p-4 sm:p-5 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-base sm:text-lg font-bold text-gray-800 dark:text-white">
                            {{ $partyId ? 'تعديل البيانات' : 'إضافة عميل / طرف جديد' }}
                        </h3>
                        <button wire:click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl font-bold leading-none">&times;</button>
                    </div>

                    <form wire:submit="save" class="p-4 sm:p-6 space-y-4 overflow-y-auto flex-1">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">الاسم الكامل <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="name" class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                            @error('name')
                                <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">نوع الطرف <span class="text-red-500">*</span></label>
                                <select wire:model="type" class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none bg-white">
                                    <option value="customer">زبون (Customer)</option>
                                    <option value="supplier">مورد (Supplier)</option>
                                    <option value="both">كلاهما (Both)</option>
                                </select>
                                @error('type')
                                    <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">رقم الهاتف</label>
                                <input type="text" wire:model="phone" class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">البريد الإلكتروني</label>
                                <input type="email" wire:model="email" class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">الرقم الضريبي</label>
                                <input type="text" wire:model="tax_number" class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">الموقع / العنوان</label>
                            <input type="text" wire:model="address" placeholder="مثال: نابلس - شارع سفيان" class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">الرصيد الافتتاحي</label>
                            <input type="number" step="0.01" wire:model="opening_balance" class="w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                        </div>

                        <div class="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <button type="button" wire:click="closeModal" class="px-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">إلغاء</button>
                            <button type="submit" wire:loading.attr="disabled" class="px-5 py-2 bg-gray-900 hover:bg-black dark:bg-gray-700 dark:hover:bg-gray-600 text-white rounded-lg text-sm font-medium transition">
                                <span wire:loading.remove>{{ $partyId ? 'تحديث البيانات' : 'حفظ البيانات' }}</span>
                                <span wire:loading>جاري الحفظ...</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <!-- مودال كشف الحساب -->
        @if ($showStatementModal && $selectedPartyForStatement)
            @php
                $transactions = collect();

                foreach ($selectedPartyForStatement->orders as $order) {
                    $transactions->push([
                        'date' => $order->created_at,
                        'description' => 'فاتورة مبيعات #' . $order->id,
                        'debit' => (float) $order->total,
                        'credit' => 0.00,
                    ]);
                }

                foreach ($selectedPartyForStatement->payments as $payment) {
                    $transactions->push([
                        'date' => $payment->created_at,
                        'description' => 'سداد دفعة ' . ($payment->notes ? '(' . $payment->notes . ')' : ''),
                        'debit' => 0.00,
                        'credit' => (float) $payment->amount,
                    ]);
                }

                $sortedTransactions = $transactions->sortBy('date');
                $runningBalance = (float) $selectedPartyForStatement->opening_balance;
            @endphp

            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-2xl my-auto overflow-hidden border border-gray-100 dark:border-gray-700 max-h-[90vh] flex flex-col">

                    <div class="flex justify-between items-center p-4 sm:p-5 border-b border-gray-100 dark:border-gray-700">
                        <div>
                            <h3 class="text-base sm:text-lg font-bold text-gray-800 dark:text-white">كشف حساب</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $selectedPartyForStatement->name }} ({{ $selectedPartyForStatement->phone ?? 'بدون رقم' }})</p>
                        </div>
                        <button wire:click="closeStatementModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl font-bold leading-none">&times;</button>
                    </div>

                    <div class="p-4 sm:p-6 space-y-4 overflow-y-auto flex-1">
                        <div class="grid grid-cols-2 gap-2 text-xs sm:text-sm bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg dark:text-gray-200">
                            <div>الرصيد الافتتاحي: <span class="font-bold">{{ number_format($selectedPartyForStatement->opening_balance, 2) }}</span></div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-right text-xs min-w-[500px] dark:text-gray-300">
                                <thead>
                                    <tr class="bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                        <th class="p-2">التاريخ</th>
                                        <th class="p-2">البيان</th>
                                        <th class="p-2">مدين (فاتورة)</th>
                                        <th class="p-2">دائن (سداد)</th>
                                        <th class="p-2">الرصيد</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr>
                                        <td class="p-2 whitespace-nowrap">{{ $selectedPartyForStatement->created_at->format('Y-m-d') }}</td>
                                        <td class="p-2 font-medium">رصيد افتتاحي</td>
                                        <td class="p-2 whitespace-nowrap">{{ number_format($selectedPartyForStatement->opening_balance, 2) }}</td>
                                        <td class="p-2 whitespace-nowrap">0.00</td>
                                        <td class="p-2 whitespace-nowrap font-bold">{{ number_format($runningBalance, 2) }}</td>
                                    </tr>

                                    @foreach ($sortedTransactions as $item)
                                        @php
                                            $runningBalance += ($item['debit'] - $item['credit']);
                                        @endphp
                                        <tr>
                                            <td class="p-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($item['date'])->format('Y-m-d H:i') }}</td>
                                            <td class="p-2">{{ $item['description'] }}</td>
                                            <td class="p-2 whitespace-nowrap text-red-600 dark:text-red-400">
                                                {{ $item['debit'] > 0 ? number_format($item['debit'], 2) : '-' }}
                                            </td>
                                            <td class="p-2 whitespace-nowrap text-emerald-600 dark:text-emerald-400">
                                                {{ $item['credit'] > 0 ? number_format($item['credit'], 2) : '-' }}
                                            </td>
                                            <td class="p-2 whitespace-nowrap font-semibold">
                                                {{ number_format($runningBalance, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- أزرار الإجراءات والطباعة -->
                    <div class="flex flex-col sm:flex-row justify-end gap-2 p-4 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                        <!-- زر الواتساب -->
                        <button type="button" wire:click="sendStatementWhatsapp({{ $selectedPartyForStatement->id }})"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-medium flex items-center justify-center gap-1 transition">
                            <span>إرسال واتساب</span> 💬
                        </button>

                        <!-- زر الطباعة الحرارية عبر RawBT -->
                        <button type="button" wire:click="printStatementThermal({{ $selectedPartyForStatement->id }})"
                            class="px-4 py-2 bg-gray-800 hover:bg-gray-900 dark:bg-gray-700 dark:hover:bg-gray-600 text-white rounded-lg text-xs font-medium flex items-center justify-center gap-1 transition">
                            <span>طباعة حرارية</span> 🧾
                        </button>

                        <button type="button" wire:click="closeStatementModal"
                            class="px-4 py-2 border border-gray-200 dark:border-gray-600 rounded-lg text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 text-center transition">إغلاق</button>
                    </div>
                </div>
            </div>
        @endif

    </div>
</flux:main>

@script
    <script>
        // الاستماع لحدث الطباعة الحرارية عبر RawBT
        $wire.on('do-statement-print', (event) => {
            const s = event.data;

            let text = "";
            text += "--------------------------------\n";
            if (s.store_name) {
                text += "        " + s.store_name + "        \n";
            }
            text += "          كشف حساب          \n";
            text += "--------------------------------\n";
            text += "العميل: " + s.party_name + "\n";
            text += "الهاتف: " + s.party_phone + "\n";
            text += "التاريخ: " + s.date + "\n";
            text += "--------------------------------\n";
            text += "الرصيد الافتتاحي: " + s.opening_balance + "\n";
            text += "--------------------------------\n";

            s.items.forEach(item => {
                text += item.date + " | " + item.desc + "\n";
                text += "  مدين: " + item.debit + " | دائن: " + item.credit + "\n";
                text += "  الرصيد: " + item.bal + "\n";
                text += "................................\n";
            });

            text += "--------------------------------\n";
            text += "الرصيد النهائي: " + s.final_balance + " شيكل\n";
            text += "--------------------------------\n\n\n\n";

            const intentUrl = "intent:" + encodeURIComponent(text) +
                "#Intent;" +
                "scheme=rawbt;" +
                "package=ru.a402d.rawbtprinter;" +
                "S.type=text/plain;" +
                "end;";

            window.location.href = intentUrl;
        });

        // الاستماع لحدث فتح الواتساب
        $wire.on('open-whatsapp', (event) => {
            window.open(event.url, '_blank');
        });
    </script>
@endscript
