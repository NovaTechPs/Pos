<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Customer;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public int $perPage = 10;
    public bool $showModal = false;
    public ?int $customerId = null;

    // البيانات الأساسية
    public string $name = '';
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $address = null;
    public float $opening_balance = 0.0;
    public ?string $notes = null;

    // حالة كشف الحساب
    public bool $showStatementModal = false;
    public ?Customer $selectedCustomerForStatement = null;

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'opening_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ];
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

    public function editCustomer($id)
    {
        $this->resetValidation();
        $tenantId = session('active_tenant_id') ?? auth()->user()->tenant_id;

        $customer = Customer::where('tenant_id', $tenantId)->findOrFail($id);

        $this->customerId = $customer->id;
        $this->name = $customer->name;
        $this->phone = $customer->phone;
        $this->email = $customer->email;
        $this->address = $customer->address;
        $this->opening_balance = (float) $customer->opening_balance;
        $this->notes = $customer->notes;

        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset([
            'name', 'phone', 'email', 'address',
            'opening_balance', 'notes', 'customerId'
        ]);
        $this->opening_balance = 0.0;
    }

    public function openStatementModal($id)
    {
        $tenantId = session('active_tenant_id') ?? auth()->user()->tenant_id;
        $this->selectedCustomerForStatement = Customer::where('tenant_id', $tenantId)
            ->with(['invoices', 'payments'])
            ->findOrFail($id);

        $this->showStatementModal = true;
    }

    public function closeStatementModal()
    {
        $this->showStatementModal = false;
        $this->selectedCustomerForStatement = null;
    }

    public function save()
    {
        $validated = $this->validate();
        $tenantId = session('active_tenant_id') ?? auth()->user()->tenant_id;

        if ($this->customerId) {
            $customer = Customer::where('tenant_id', $tenantId)->findOrFail($this->customerId);
            $customer->update($validated);
            session()->flash('message', 'تم تعديل بيانات العميل بنجاح!');
        } else {
            $validated['tenant_id'] = $tenantId;
            Customer::create($validated);
            session()->flash('message', 'تمت إضافة العميل بنجاح!');
        }

        $this->closeModal();
    }

    public function deleteCustomer($id)
    {
        $tenantId = session('active_tenant_id') ?? auth()->user()->tenant_id;
        $customer = Customer::where('tenant_id', $tenantId)->find($id);

        if ($customer) {
            $customer->delete();
            session()->flash('message', 'تم حذف العميل بنجاح.');
        }
    }

    public function render()
    {
        $tenantId = session('active_tenant_id') ?? auth()->user()->tenant_id;

        $customers = Customer::where('tenant_id', $tenantId)
            ->where(function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
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
<div class="p-3 sm:p-6 bg-gray-50 min-h-screen space-y-4 sm:space-y-6" dir="rtl" x-data="thermalPrinter()">

    <!-- الهيدر العلوي -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-2 sm:mb-6">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800">إدارة العملاء</h1>
            <p class="text-xs sm:text-sm text-gray-500 mt-1">عرض وإدارة العملاء وعناوينهم</p>
        </div>

        <button wire:click="openCreateModal"
            class="w-full sm:w-auto justify-center bg-gray-900 hover:bg-black text-white px-4 py-2.5 rounded-lg text-sm font-medium flex items-center gap-2 shadow-sm transition">
            <span>إضافة عميل جديد</span>
            <span class="text-lg leading-none">+</span>
        </button>
    </div>

    <!-- رسائل التنبيه -->
    @if (session()->has('message'))
        <div class="p-4 text-sm text-green-800 bg-green-100 rounded-lg border border-green-200">
            {{ session('message') }}
        </div>
    @endif

    <!-- شريط البحث والتصفح -->
    <div class="bg-white p-3 sm:p-4 rounded-xl shadow-sm flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="relative w-full sm:w-1/2 md:w-1/3">
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="ابحث بالاسم، الهاتف أو العنوان..."
                class="w-full pl-4 pr-10 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-gray-200">
            <span class="absolute right-3 top-2.5 text-gray-400">🔍</span>
        </div>

        <select wire:model.live="perPage" class="w-full sm:w-auto border border-gray-200 rounded-lg text-sm p-2 text-gray-600 bg-white">
            <option value="10">10 لكل صفحة</option>
            <option value="25">25 لكل صفحة</option>
            <option value="50">50 لكل صفحة</option>
        </select>
    </div>

    <!-- جدول عرض العملاء بالتجاوب الأصلي -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
        <table class="w-full text-right border-collapse min-w-[650px]">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                    <th class="p-3 sm:p-4">#</th>
                    <th class="p-3 sm:p-4">اسم العميل</th>
                    <th class="p-3 sm:p-4">رقم الهاتف</th>
                    <th class="p-3 sm:p-4 hidden md:table-cell">البريد الإلكتروني</th>
                    <th class="p-3 sm:p-4 hidden sm:table-cell">الموقع / العنوان</th>
                    <th class="p-3 sm:p-4">الرصيد الافتتاحي</th>
                    <th class="p-3 sm:p-4 text-center">الإجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-xs sm:text-sm">
                @forelse ($customers as $customer)
                    <tr class="hover:bg-gray-50/50 transition">
                        <td class="p-3 sm:p-4 text-gray-400">{{ $loop->iteration }}</td>
                        <td class="p-3 sm:p-4 font-semibold text-gray-800 whitespace-nowrap">{{ $customer->name }}</td>
                        <td class="p-3 sm:p-4 text-gray-600 whitespace-nowrap" dir="ltr">{{ $customer->phone ?? '-' }}</td>
                        <td class="p-3 sm:p-4 text-gray-500 hidden md:table-cell">{{ $customer->email ?? '-' }}</td>
                        <td class="p-3 sm:p-4 text-gray-700 font-medium hidden sm:table-cell">{{ $customer->address ?? '-' }}</td>
                        <td class="p-3 sm:p-4 font-medium text-gray-800 whitespace-nowrap">{{ number_format($customer->opening_balance, 2) }}</td>
                        <td class="p-3 sm:p-4 text-center whitespace-nowrap">
                            <div class="flex justify-center items-center gap-2 sm:gap-3">
                                <button wire:click="openStatementModal({{ $customer->id }})" class="p-1 text-gray-400 hover:text-emerald-600 transition text-base" title="كشف حساب">📜</button>
                                <button wire:click="editCustomer({{ $customer->id }})" class="p-1 text-gray-400 hover:text-blue-600 transition text-base" title="تعديل">✏️</button>
                                <button wire:click="deleteCustomer({{ $customer->id }})" wire:confirm="هل أنت متأكد من حذف هذا العميل؟" class="p-1 text-gray-400 hover:text-red-600 transition text-base" title="حذف">🗑️</button>
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

    <!-- نافذة إضافة / تعديل عميل المتجاوبة -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-xl my-auto overflow-hidden border border-gray-100 animate-fadeIn max-h-[90vh] flex flex-col">
                <div class="flex justify-between items-center p-4 sm:p-5 border-b border-gray-100">
                    <h3 class="text-base sm:text-lg font-bold text-gray-800">
                        {{ $customerId ? 'تعديل بيانات العميل' : 'إضافة عميل جديد' }}
                    </h3>
                    <button wire:click="closeModal" class="text-gray-400 hover:text-gray-600 text-2xl font-bold leading-none">&times;</button>
                </div>

                <form wire:submit="save" class="p-4 sm:p-6 space-y-4 overflow-y-auto flex-1">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">اسم العميل <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="name" class="w-full border border-gray-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                        @error('name') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">رقم الهاتف</label>
                            <input type="text" wire:model="phone" class="w-full border border-gray-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">البريد الإلكتروني</label>
                            <input type="email" wire:model="email" class="w-full border border-gray-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">الموقع / العنوان</label>
                        <input type="text" wire:model="address" placeholder="مثال: نابلس - شارع سفيان" class="w-full border border-gray-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">الرصيد الافتتاحي</label>
                            <input type="number" step="0.01" wire:model="opening_balance" class="w-full border border-gray-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">ملاحظات</label>
                            <input type="text" wire:model="notes" class="w-full border border-gray-200 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-gray-200 focus:outline-none">
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
                        <button type="button" wire:click="closeModal" class="px-4 py-2 border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 transition">إلغاء</button>
                        <button type="submit" wire:loading.attr="disabled" class="px-5 py-2 bg-gray-900 hover:bg-black text-white rounded-lg text-sm font-medium transition">
                            <span wire:loading.remove>{{ $customerId ? 'تحديث البيانات' : 'حفظ البيانات' }}</span>
                            <span wire:loading>جاري الحفظ...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- مودال كشف الحساب المتجاوب -->
    @if ($showStatementModal && $selectedCustomerForStatement)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl my-auto overflow-hidden border border-gray-100 max-h-[90vh] flex flex-col">
                <div class="flex justify-between items-center p-4 sm:p-5 border-b border-gray-100">
                    <div>
                        <h3 class="text-base sm:text-lg font-bold text-gray-800">كشف حساب العميل</h3>
                        <p class="text-xs text-gray-500">{{ $selectedCustomerForStatement->name }} ({{ $selectedCustomerForStatement->phone ?? 'بدون رقم' }})</p>
                    </div>
                    <button wire:click="closeStatementModal" class="text-gray-400 hover:text-gray-600 text-2xl font-bold leading-none">&times;</button>
                </div>

                <div class="p-4 sm:p-6 space-y-4 overflow-y-auto flex-1" id="statement-print-area">
                    <div class="flex justify-between text-xs sm:text-sm bg-gray-50 p-3 rounded-lg">
                        <span>الرصيد الافتتاحي:</span>
                        <span class="font-bold">{{ number_format($selectedCustomerForStatement->opening_balance, 2) }}</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-right text-xs min-w-[300px]">
                            <thead>
                                <tr class="bg-gray-100 text-gray-600">
                                    <th class="p-2">التاريخ</th>
                                    <th class="p-2">البيان</th>
                                    <th class="p-2">مدين</th>
                                    <th class="p-2">دائن</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr>
                                    <td class="p-2 whitespace-nowrap">{{ $selectedCustomerForStatement->created_at->format('Y-m-d') }}</td>
                                    <td class="p-2">رصيد افتتاحي</td>
                                    <td class="p-2 whitespace-nowrap">{{ number_format($selectedCustomerForStatement->opening_balance, 2) }}</td>
                                    <td class="p-2 whitespace-nowrap">0.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row justify-between gap-3 p-4 border-t border-gray-100 bg-gray-50">
                    <button type="button" @click="connectPrinter()" class="w-full sm:w-auto px-4 py-2 border border-gray-300 bg-white rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 flex items-center justify-center gap-1">
                        🔌 ربط الطابعة
                    </button>
                    <div class="flex gap-2 w-full sm:w-auto">
                        <button type="button" wire:click="closeStatementModal" class="flex-1 sm:flex-none px-4 py-2 border border-gray-200 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-50 text-center">إغلاق</button>
                        <button type="button" @click="printStatement({{ json_encode($selectedCustomerForStatement) }})" class="flex-1 sm:flex-none px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium flex items-center justify-center gap-2">
                            <span>طباعة حرارية</span> 🖨️
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
</flux:main>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('thermalPrinter', () => ({
        port: null,

        async connectPrinter() {
            try {
                this.port = await navigator.serial.requestPort();
                await this.port.open({ baudRate: 9600 });
                alert('تم الاتصال بالطابعة بنجاح');
            } catch (e) {
                alert('تعذر الاتصال بالطابعة: ' + e.message);
            }
        },

        async printStatement(customer) {
            if (!this.port) {
                const connectFirst = confirm("لم يتم ربط طابعة Web Serial بعد. هل تريد الربط الآن؟");
                if (connectFirst) {
                    await this.connectPrinter();
                }
                if (!this.port) return;
            }

            try {
                const writer = this.port.writable.getWriter();

                // تحضير أوامر ESC/POS
                const esc = '\x1B';
                const init = esc + '@';
                const alignCenter = esc + 'a' + '\x01';
                const alignRight = esc + 'a' + '\x02';
                const cut = esc + 'i';

                let rawText = init + alignCenter;
                rawText += "==============================\n";
                rawText += "         STATEMENT            \n";
                rawText += "==============================\n";
                rawText += alignRight;
                rawText += `Name: ${customer.name}\n`;
                rawText += `Phone: ${customer.phone || '-'}\n`;
                rawText += `Date: ${new Date().toISOString().split('T')[0]}\n`;
                rawText += "------------------------------\n";
                rawText += `Opening Bal: ${customer.opening_balance}\n`;
                rawText += "------------------------------\n\n\n\n";
                rawText += cut;

                const encoder = new TextEncoder();
                await writer.write(encoder.encode(rawText));
                writer.releaseLock();
            } catch (e) {
                alert('خطأ أثناء الطباعة: ' + e.message);
            }
        }
    }));
});
</script>
