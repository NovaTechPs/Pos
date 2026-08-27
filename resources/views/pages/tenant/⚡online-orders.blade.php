<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Order;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public ?Order $selectedOrder = null;
    public bool $showDetailModal = false;

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedStatusFilter()
    {
        $this->resetPage();
    }

    // معاينة تفاصيل الطلب
    public function viewOrder(int $orderId)
    {
        $tenantId = session('active_tenant_id');

        $this->selectedOrder = Order::where('tenant_id', $tenantId)
            ->where('type', 'online')
            ->with(['items.product'])
            ->findOrFail($orderId);

        $this->showDetailModal = true;
    }

    // تحديث حالة الطلب يدوياً
    public function updateStatus(int $orderId, string $status)
    {
        $tenantId = session('active_tenant_id');

        $order = Order::where('tenant_id', $tenantId)->where('type', 'online')->findOrFail($orderId);

        $updateData = ['status' => $status];

        if ($status === 'completed') {
            $updateData['payment_status'] = 'paid';
            $updateData['paid_amount'] = $order->total;
        }

        $order->update($updateData);

        if ($this->selectedOrder && $this->selectedOrder->id === $orderId) {
            $this->selectedOrder->refresh();
        }

        session()->flash('message', 'تم تحديث حالة الطلب بنجاح.');
    }

    // فتح رابط الواتساب في تبويب جديد عبر JavaScript
    public function sendWhatsAppNotification(int $orderId)
    {
        $tenantId = session('active_tenant_id');
        $order = Order::where('tenant_id', $tenantId)->where('type', 'online')->findOrFail($orderId);

        // تنظيف رقم الهاتف
        $cleanPhone = preg_replace('/[^0-9]/', '', $order->customer_phone);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '970' . substr($cleanPhone, 1);
        }

        // تجهيز النص
        $statusMessage = match ($order->status) {
            'pending'    => "⏳ طلبكم رقم (*{$order->invoice_number}*) قيد الانتظار حالياً، وسنوافيكم بالتحديثات فور البدء بتجهيزه.",
            'processing' => "👨‍🍳 مرحباً {$order->customer_name}، جاري تحضير طلبكم رقم (*{$order->invoice_number}*) الآن!",
            'completed'  => "✅ أهلاً {$order->customer_name}، تم إكمال طلبكم رقم (*{$order->invoice_number}*) بنجاح وهو جاهز للتسليم/التوصيل.",
            'cancelled'  => "❌ مرحباً {$order->customer_name}، نأسف لإبلاغك بأنه تم إلغاء طلبك رقم (*{$order->invoice_number}*).",
            default      => "تحديث بشأن طلبكم رقم (*{$order->invoice_number}*): الحالة الحالية هي {$order->status}.",
        };

        $fullMessage = "مرحباً بك! 👋\n\n{$statusMessage}\n\nشكراً لتسوقك معنا! 🌸";
        $whatsappUrl = "https://wa.me/{$cleanPhone}?text=" . urlencode($fullMessage);

        // إرسال أمر لتشغيل نافذة جديدة في المتصفح دون مغادرة الصفحة الحالية
        $this->js("window.open('{$whatsappUrl}', '_blank')");
    }

    public function closeModal()
    {
        $this->showDetailModal = false;
        $this->selectedOrder = null;
    }

    public function render()
    {
        $tenantId = session('active_tenant_id');

        $orders = Order::where('tenant_id', $tenantId)
            ->where('type', 'online')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('invoice_number', 'like', '%' . $this->search . '%')
                        ->orWhere('customer_name', 'like', '%' . $this->search . '%')
                        ->orWhere('customer_phone', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->statusFilter, function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->latest()
            ->paginate(10);

        return $this->view([
            'orders' => $orders,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">
    <div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-6" dir="rtl">

        <!-- الهيدر -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 pb-6 border-b border-zinc-200 dark:border-zinc-800">
            <div>
                <flux:heading size="xl">طلبات الأونلاين</flux:heading>
                <flux:subheading>متابعة ومعالجة الطلبات الواردة وإرسال التحديثات للزبائن يدوياً عبر الواتساب</flux:subheading>
            </div>
        </div>

        <!-- التنبيهات -->
        @if (session()->has('message'))
            <flux:badge variant="success" class="w-full justify-start p-3 text-sm">
                {{ session('message') }}
            </flux:badge>
        @endif

        <!-- الفلاتر والبحث -->
        <div class="flex flex-col sm:flex-row gap-4 justify-between items-center">
            <div class="w-full sm:w-80">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="بحث باسم الزبون، الهاتف، أو رقم الفاتورة..." icon="magnifying-glass" />
            </div>

            <div class="w-full sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <flux:select.option value="">جميع الحالات</flux:select.option>
                    <flux:select.option value="pending">قيد الانتظار</flux:select.option>
                    <flux:select.option value="processing">جاري التحضير</flux:select.option>
                    <flux:select.option value="completed">مكتمل</flux:select.option>
                    <flux:select.option value="cancelled">ملغي</flux:select.option>
                </flux:select>
            </div>
        </div>

        <!-- جدول الطلبات -->
        <flux:card class="p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right border-collapse">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-600 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                        <tr>
                            <th class="p-3">رقم الفاتورة</th>
                            <th class="p-3">الزبون</th>
                            <th class="p-3">العنوان</th>
                            <th class="p-3">الإجمالي</th>
                            <th class="p-3">الحالة</th>
                            <th class="p-3">التاريخ</th>
                            <th class="p-3 text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($orders as $order)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                                <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                    {{ $order->invoice_number }}
                                </td>
                                <td class="p-3">
                                    <div class="font-medium">{{ $order->customer_name }}</div>
                                    <div class="text-xs text-zinc-500" dir="ltr">{{ $order->customer_phone }}</div>
                                </td>
                                <td class="p-3 text-zinc-600 dark:text-zinc-400 max-w-xs truncate">
                                    {{ $order->customer_address }}
                                </td>
                                <td class="p-3 font-bold text-emerald-600 dark:text-emerald-400">
                                    {{ number_format($order->total, 2) }}
                                </td>
                                <td class="p-3">
                                    @switch($order->status)
                                        @case('pending')
                                            <flux:badge variant="warning">قيد الانتظار</flux:badge>
                                            @break
                                        @case('processing')
                                            <flux:badge variant="info">جاري التحضير</flux:badge>
                                            @break
                                        @case('completed')
                                            <flux:badge variant="success">مكتمل</flux:badge>
                                            @break
                                        @case('cancelled')
                                            <flux:badge variant="danger">ملغي</flux:badge>
                                            @break
                                        @default
                                            <flux:badge variant="subtle">{{ $order->status }}</flux:badge>
                                    @endswitch
                                </td>
                                <td class="p-3 text-xs text-zinc-500">
                                    {{ $order->created_at->format('Y-m-d H:i') }}
                                </td>
                                <td class="p-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <flux:button size="xs" variant="subtle" icon="eye" wire:click="viewOrder({{ $order->id }})">
                                            معاينة
                                        </flux:button>
                                        <flux:button size="xs" variant="subtle" class="text-emerald-600 dark:text-emerald-400 font-bold" wire:click="sendWhatsAppNotification({{ $order->id }})">
                                            💬 واتساب
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-12 text-zinc-500">
                                    لا توجد طلبات أونلاين مطابقة للبحث.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($orders->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                    {{ $orders->links() }}
                </div>
            @endif
        </flux:card>

        <!-- Modal تفاصيل الطلب -->
        <flux:modal wire:model="showDetailModal" name="order-details-modal" class="md:w-3/4 max-w-4xl space-y-6">
            @if ($selectedOrder)
                <div class="flex justify-between items-center border-b border-zinc-200 dark:border-zinc-800 pb-4">
                    <div>
                        <flux:heading size="lg">تفاصيل الطلب {{ $selectedOrder->invoice_number }}</flux:heading>
                        <flux:subheading>بتاريخ {{ $selectedOrder->created_at->format('Y-m-d H:i A') }}</flux:subheading>
                    </div>
                    <flux:button variant="ghost" icon="x-mark" wire:click="closeModal" />
                </div>

                <!-- تفاصيل الزبون وتغيير الحالة -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-zinc-50 dark:bg-zinc-800/50 p-4 rounded-lg">
                    <div class="space-y-1">
                        <div class="text-xs text-zinc-500">معلومات الزبون</div>
                        <div class="font-bold text-base">{{ $selectedOrder->customer_name }}</div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-300" dir="ltr">
                            {{ $selectedOrder->customer_phone }}
                        </div>
                        <div class="text-sm text-zinc-500">{{ $selectedOrder->customer_address }}</div>
                    </div>

                    <div class="space-y-3">
                        <div class="text-xs text-zinc-500">تحديث حالة الطلب</div>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="xs"
                                variant="{{ $selectedOrder->status === 'pending' ? 'primary' : 'subtle' }}"
                                wire:click="updateStatus({{ $selectedOrder->id }}, 'pending')">
                                قيد الانتظار
                            </flux:button>
                            <flux:button size="xs"
                                variant="{{ $selectedOrder->status === 'processing' ? 'primary' : 'subtle' }}"
                                wire:click="updateStatus({{ $selectedOrder->id }}, 'processing')">
                                جاري التحضير
                            </flux:button>
                            <flux:button size="xs"
                                variant="{{ $selectedOrder->status === 'completed' ? 'primary' : 'subtle' }}"
                                wire:click="updateStatus({{ $selectedOrder->id }}, 'completed')">
                                مكتمل
                            </flux:button>
                            <flux:button size="xs"
                                variant="{{ $selectedOrder->status === 'cancelled' ? 'danger' : 'subtle' }}"
                                wire:click="updateStatus({{ $selectedOrder->id }}, 'cancelled')">
                                إلغاء
                            </flux:button>
                        </div>

                        <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700">
                            <flux:button size="xs" variant="primary" class="bg-emerald-600 hover:bg-emerald-700 w-full" wire:click="sendWhatsAppNotification({{ $selectedOrder->id }})">
                                📱 إرسال حالة الطلب الحالية للزبون عبر الواتساب (صفحة جديدة)
                            </flux:button>
                        </div>
                    </div>
                </div>

                <!-- جدول المنتجات في الطلب -->
                <div class="space-y-2">
                    <flux:heading size="sm">المنتجات المطلوبة</flux:heading>
                    <div class="border border-zinc-200 dark:border-zinc-800 rounded-lg overflow-hidden">
                        <table class="w-full text-sm text-right">
                            <thead class="bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400">
                                <tr>
                                    <th class="p-2">المنتج</th>
                                    <th class="p-2">السعر الفردي</th>
                                    <th class="p-2">الكمية</th>
                                    <th class="p-2">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach ($selectedOrder->items as $item)
                                    <tr>
                                        <td class="p-2 font-medium">{{ $item->product->name ?? 'منتج غير محدد' }}</td>
                                        <td class="p-2">{{ number_format($item->unit_price, 2) }}</td>
                                        <td class="p-2 font-bold">{{ $item->quantity }}</td>
                                        <td class="p-2 font-bold text-emerald-600 dark:text-emerald-400">
                                            {{ number_format($item->total_price, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- المجموع الكلي -->
                <div class="flex justify-between items-center pt-4 border-t border-zinc-200 dark:border-zinc-800 text-base font-bold">
                    <span>المجموع الكلي للطلب:</span>
                    <span class="text-xl text-emerald-600 dark:text-emerald-400">{{ number_format($selectedOrder->total, 2) }}</span>
                </div>
            @endif
        </flux:modal>
    </div>
</flux:main>
