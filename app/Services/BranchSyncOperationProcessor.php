<?php

namespace App\Services;

use App\Models\BranchProduct;
use App\Models\BranchSyncOperation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class BranchSyncOperationProcessor
{
    public function process(BranchSyncOperation $operation): void
    {
        if ($operation->operation_type === 'sale.upsert') {
            $this->processSale($operation);

            return;
        }

        if ($operation->operation_type === 'shift.closed') {
            $this->processShiftClose($operation);

            return;
        }

        if ($operation->operation_type === 'shift.opened') {
            $this->processShiftOpen($operation);

            return;
        }

        throw new RuntimeException('نوع عملية المزامنة غير مدعوم.');
    }

    private function processShiftOpen(BranchSyncOperation $operation): void
    {
        $shiftData = Validator::make($operation->payload['shift'] ?? [], [
            'sync_uuid' => ['required', 'uuid'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'opened_at' => ['required', 'date'],
            'opened_by' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ])->validate();
        $this->assertTenantUser($operation->tenant_id, $shiftData['opened_by'] ?? null);

        $shift = Shift::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('branch_id', $operation->branch_id)
            ->where('sync_uuid', $shiftData['sync_uuid'])
            ->lockForUpdate()
            ->first();

        if ($shift) {
            return;
        }

        Shift::query()->create([
            'tenant_id' => $operation->tenant_id,
            'branch_id' => $operation->branch_id,
            'sync_uuid' => $shiftData['sync_uuid'],
            'opening_cash' => $shiftData['opening_cash'],
            'status' => 'open',
            'opened_at' => $shiftData['opened_at'],
            'opened_by' => $shiftData['opened_by'] ?? null,
            'notes' => $shiftData['notes'] ?? null,
        ]);
    }

    private function processShiftClose(BranchSyncOperation $operation): void
    {
        $shiftData = $this->validateShift($operation->payload['shift'] ?? []);
        $this->assertTenantUser($operation->tenant_id, $shiftData['opened_by'] ?? null);
        $this->assertTenantUser($operation->tenant_id, $shiftData['closed_by'] ?? null);

        $shift = Shift::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('branch_id', $operation->branch_id)
            ->where('sync_uuid', $shiftData['sync_uuid'])
            ->lockForUpdate()
            ->first();

        $attributes = [
            'tenant_id' => $operation->tenant_id,
            'branch_id' => $operation->branch_id,
            'sync_uuid' => $shiftData['sync_uuid'],
            'opening_cash' => $shiftData['opening_cash'],
            'expected_cash' => $shiftData['expected_cash'],
            'actual_cash' => $shiftData['actual_cash'],
            'difference' => $shiftData['difference'],
            'total_sales' => $shiftData['total_sales'],
            'total_returns' => $shiftData['total_returns'],
            'status' => 'closed',
            'opened_at' => $shiftData['opened_at'],
            'opened_by' => $shiftData['opened_by'] ?? null,
            'closed_at' => $shiftData['closed_at'],
            'closed_by' => $shiftData['closed_by'] ?? null,
            'notes' => $shiftData['notes'] ?? null,
        ];

        if ($shift) {
            $shift->update($attributes);

            return;
        }

        Shift::query()->create($attributes);
    }

    private function processSale(BranchSyncOperation $operation): void
    {
        $payload = $this->validateSale($operation->payload);
        $orderData = $payload['order'];
        $shiftData = $payload['shift'];
        $this->assertTenantUser($operation->tenant_id, $orderData['created_by'] ?? null);
        $this->assertTenantUser($operation->tenant_id, $shiftData['opened_by'] ?? null);

        if (($orderData['customer_id'] ?? null) !== null && ! Party::query()
            ->where('tenant_id', $operation->tenant_id)
            ->whereKey($orderData['customer_id'])
            ->exists()) {
            throw new RuntimeException('العميل غير موجود في قاعدة البيانات المركزية.');
        }

        $shift = Shift::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('branch_id', $operation->branch_id)
            ->where('sync_uuid', $shiftData['sync_uuid'])
            ->lockForUpdate()
            ->first();

        if (! $shift) {
            $shift = Shift::query()->create([
                'tenant_id' => $operation->tenant_id,
                'branch_id' => $operation->branch_id,
                'sync_uuid' => $shiftData['sync_uuid'],
                'opening_cash' => $shiftData['opening_cash'],
                'status' => 'open',
                'opened_at' => $shiftData['opened_at'],
                'opened_by' => $shiftData['opened_by'] ?? null,
                'notes' => $shiftData['notes'] ?? null,
            ]);
        }

        $order = Order::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('sync_uuid', $orderData['sync_uuid'])
            ->lockForUpdate()
            ->first();

        if (Order::query()
            ->where('tenant_id', $operation->tenant_id)
            ->where('invoice_number', $orderData['invoice_number'])
            ->when($order, fn ($query) => $query->where('sync_uuid', '!=', $orderData['sync_uuid']))
            ->exists()) {
            throw new RuntimeException('رقم الفاتورة مستخدم بالفعل في المركز.');
        }

        if ($order) {
            throw new RuntimeException('الفاتورة موجودة مسبقًا في المركز؛ أرسل مرتجعًا بدل تعديل فاتورة متزامنة.');
        }

        $items = [];
        $quantitiesByProduct = [];
        foreach ($payload['items'] as $item) {
            $product = Product::query()
                ->where('tenant_id', $operation->tenant_id)
                ->whereKey($item['product_id'])
                ->first();
            $branchProduct = BranchProduct::query()
                ->where('tenant_id', $operation->tenant_id)
                ->where('branch_id', $operation->branch_id)
                ->where('product_id', $item['product_id'])
                ->lockForUpdate()
                ->first();

            if (! $product || ! $branchProduct) {
                throw new RuntimeException('أحد المنتجات غير مرتبط بفرع المزامنة في المركز.');
            }

            $quantity = (float) $item['quantity'];
            $quantitiesByProduct[$product->id] = ($quantitiesByProduct[$product->id] ?? 0) + $quantity;
            $items[] = [$item, $branchProduct, $quantity];
        }

        if (! TenantSetting::getBool($operation->tenant_id, 'allow_negative_stock', false)) {
            foreach ($quantitiesByProduct as $productId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $available = (float) BranchProduct::query()
                    ->where('tenant_id', $operation->tenant_id)
                    ->where('branch_id', $operation->branch_id)
                    ->where('product_id', $productId)
                    ->value('stock_quantity');

                if (TenantSetting::getBool($operation->tenant_id, 'unified_stock', false)) {
                    $available = (float) BranchProduct::query()
                        ->where('tenant_id', $operation->tenant_id)
                        ->where('product_id', $productId)
                        ->sum('stock_quantity');
                }

                if ($available < $quantity) {
                    $productName = Product::query()->whereKey($productId)->value('name') ?? $productId;
                    throw new RuntimeException("المخزون غير كافٍ لمزامنة المنتج: {$productName}.");
                }
            }
        }

        $orderAttributes = [
            'tenant_id' => $operation->tenant_id,
            'branch_id' => $operation->branch_id,
            'shift_id' => $shift->id,
            'created_by' => $orderData['created_by'] ?? null,
            'customer_id' => $orderData['customer_id'] ?? null,
            'customer_name' => $orderData['customer_name'],
            'customer_phone' => $orderData['customer_phone'] ?? null,
            'customer_address' => $orderData['customer_address'] ?? null,
            'invoice_number' => $orderData['invoice_number'],
            'type' => $orderData['type'],
            'status' => 'completed',
            'subtotal' => $orderData['subtotal'],
            'tax_amount' => $orderData['tax_amount'],
            'discount_type' => $orderData['discount_type'],
            'discount_rate' => $orderData['discount_rate'],
            'discount' => $orderData['discount'],
            'delivery_fee' => $orderData['delivery_fee'],
            'total' => $orderData['total'],
            'total_cost' => $orderData['total_cost'],
            'total_profit' => $orderData['total_profit'],
            'paid_amount' => $orderData['paid_amount'],
            'payment_status' => $orderData['payment_status'],
            'notes' => $orderData['notes'] ?? null,
            'sync_uuid' => $orderData['sync_uuid'],
        ];

        $order = Order::query()->create($orderAttributes + ['created_at' => $orderData['created_at']]);

        foreach ($items as [$item, $branchProduct, $quantity]) {
            OrderItem::query()->create([
                'tenant_id' => $operation->tenant_id,
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'quantity' => $quantity,
                'is_gift' => (bool) ($item['is_gift'] ?? false),
                'unit_price' => ! empty($item['is_gift']) ? 0 : $item['unit_price'],
                'total_price' => ! empty($item['is_gift']) ? 0 : $item['total_price'],
                'cost_price' => $item['cost_price'],
                'total_cost' => $item['total_cost'],
                'discount' => $item['discount'],
            ]);

            if ($quantity > 0) {
                $branchProduct->decrement('stock_quantity', $quantity);
            } elseif ($quantity < 0) {
                $branchProduct->increment('stock_quantity', abs($quantity));
            }
        }

        $this->syncPayment($operation, $order, $payload['payment'] ?? null);
    }

    /** @param array<string, mixed>|null $payment */
    private function syncPayment(BranchSyncOperation $operation, Order $order, ?array $payment): void
    {
        $existing = Payment::withTrashed()
            ->where('tenant_id', $operation->tenant_id)
            ->where('order_id', $order->id)
            ->first();

        if (! $payment || (float) $payment['amount'] <= 0) {
            $existing?->delete();

            return;
        }

        $attributes = [
            'tenant_id' => $operation->tenant_id,
            'branch_id' => $operation->branch_id,
            'shift_id' => $order->shift_id,
            'created_by' => $order->created_by,
            'party_id' => $order->customer_id,
            'type' => $payment['type'],
            'voucher_number' => $payment['voucher_number'],
            'amount' => $payment['amount'],
            'payment_method' => $payment['payment_method'],
            'order_id' => $order->id,
            'notes' => $payment['notes'] ?? null,
            'payment_date' => $payment['payment_date'],
        ];

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->update($attributes);
        } else {
            Payment::query()->create($attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateSale(array $payload): array
    {
        return Validator::make($payload, [
            'order' => ['required', 'array'],
            'order.sync_uuid' => ['required', 'uuid'],
            'order.invoice_number' => ['required', 'string', 'max:255'],
            'order.created_at' => ['required', 'date'],
            'order.created_by' => ['nullable', 'integer'],
            'order.customer_id' => ['nullable', 'integer'],
            'order.customer_name' => ['required', 'string', 'max:255'],
            'order.customer_phone' => ['nullable', 'string', 'max:255'],
            'order.customer_address' => ['nullable', 'string'],
            'order.type' => ['required', 'in:pos,return'],
            'order.subtotal' => ['required', 'numeric'],
            'order.tax_amount' => ['required', 'numeric'],
            'order.discount_type' => ['required', 'in:fixed,percentage'],
            'order.discount_rate' => ['required', 'numeric'],
            'order.discount' => ['required', 'numeric'],
            'order.delivery_fee' => ['required', 'numeric'],
            'order.total' => ['required', 'numeric'],
            'order.total_cost' => ['required', 'numeric'],
            'order.total_profit' => ['required', 'numeric'],
            'order.paid_amount' => ['required', 'numeric'],
            'order.payment_status' => ['required', 'in:paid,partial,unpaid'],
            'order.notes' => ['nullable', 'string'],
            'shift' => ['required', 'array'],
            'shift.sync_uuid' => ['required', 'uuid'],
            'shift.opening_cash' => ['required', 'numeric', 'min:0'],
            'shift.opened_at' => ['required', 'date'],
            'shift.opened_by' => ['nullable', 'integer'],
            'shift.notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'not_in:0'],
            'items.*.is_gift' => ['sometimes', 'boolean'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.total_price' => ['required', 'numeric'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'],
            'items.*.total_cost' => ['required', 'numeric'],
            'items.*.discount' => ['required', 'numeric', 'min:0'],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', 'numeric', 'min:0'],
            'payment.type' => ['required_with:payment', 'in:receipt,payment'],
            'payment.voucher_number' => ['required_with:payment', 'string', 'max:255'],
            'payment.payment_method' => ['required_with:payment', 'in:cash,card,bank_transfer,cheque'],
            'payment.payment_date' => ['required_with:payment', 'date'],
            'payment.notes' => ['nullable', 'string'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateShift(array $payload): array
    {
        return Validator::make($payload, [
            'sync_uuid' => ['required', 'uuid'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'expected_cash' => ['required', 'numeric'],
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'difference' => ['required', 'numeric'],
            'total_sales' => ['required', 'numeric'],
            'total_returns' => ['required', 'numeric'],
            'opened_at' => ['required', 'date'],
            'opened_by' => ['nullable', 'integer'],
            'closed_at' => ['required', 'date'],
            'closed_by' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ])->validate();
    }

    private function assertTenantUser(int $tenantId, ?int $userId): void
    {
        if ($userId !== null && ! User::query()->where('tenant_id', $tenantId)->whereKey($userId)->exists()) {
            throw new RuntimeException('الموظف غير موجود في قاعدة البيانات المركزية.');
        }
    }
}
