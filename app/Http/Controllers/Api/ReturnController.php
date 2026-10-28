<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Services\ApiShiftLedger;
use App\Services\StockMovementContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReturnController extends Controller
{
    public function findInvoice(Request $request, string $invoiceNumber): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;

        $order = Order::where('tenant_id', $tenantId)
            ->where('invoice_number', $invoiceNumber)
            ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->whereIn('type', ['pos', 'wholesale'])
            ->where('status', 'completed')
            ->with(['items.product', 'branch', 'party'])
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'لم يتم العثور على الفاتورة بهذا الرقم',
            ], 404);
        }

        $returned = OrderItem::query()->whereHas('order', fn ($query) => $query
            ->where('tenant_id', $tenantId)->where('original_order_id', $order->id)
            ->where('type', 'return')->where('status', 'completed'))->get();
        foreach ($order->items as $item) {
            $used = $returned->filter(fn ($line) => $line->original_order_item_id
                ? (int) $line->original_order_item_id === (int) $item->id
                : (int) $line->product_id === (int) $item->product_id)->sum(fn ($line) => abs((float) $line->quantity));
            $item->setAttribute('remaining_quantity', max(0, abs((float) $item->quantity) - $used));
            $ratio = (float) $order->subtotal > 0 ? (float) $order->total / (float) $order->subtotal : 0;
            $item->setAttribute('refund_unit_price', (float) $item->unit_price * $ratio);
        }

        return response()->json([
            'order' => $order,
        ]);
    }

    public function storeReturn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_order_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.original_order_item_id' => ['nullable', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
        ]);

        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;

        $returnOrder = DB::transaction(function () use ($validated, $user, $tenantId): Order {
            $shift = ApiShiftLedger::activeForWrite($tenantId, (int) $validated['branch_id'], $user->id);
            $originalOrder = Order::query()->where('tenant_id', $tenantId)
                ->where('branch_id', $validated['branch_id'])
                ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->where('branch_id', $user->branch_id))
                ->whereIn('type', ['pos', 'wholesale'])
                ->where('status', 'completed')
                ->with('items')
                ->lockForUpdate()
                ->whereKey((int) $validated['original_order_id'])->firstOrFail();

            $lines = [];
            $selectedLines = [];
            $subtotal = 0.0;
            foreach ($validated['items'] as $item) {
                $matches = $originalOrder->items->where('product_id', $item['product_id']);
                $originalItem = isset($item['original_order_item_id'])
                    ? $matches->firstWhere('id', $item['original_order_item_id'])
                    : ($matches->count() === 1 ? $matches->first() : null);
                if (! $originalItem) {
                    throw ValidationException::withMessages(['items' => 'يوجد صنف غير موجود في الفاتورة الأصلية.']);
                }
                if (in_array($originalItem->id, $selectedLines, true)) {
                    throw ValidationException::withMessages(['items' => 'لا يمكن تكرار سطر الفاتورة في المرتجع.']);
                }
                $selectedLines[] = $originalItem->id;

                $returnedQuantity = (float) OrderItem::query()->where('product_id', $originalItem->product_id)
                    ->where(fn ($query) => $query->where('original_order_item_id', $originalItem->id)->orWhereNull('original_order_item_id'))
                    ->whereHas('order', fn ($query) => $query->where('tenant_id', $tenantId)
                        ->where('original_order_id', $originalOrder->id)
                        ->whereIn('type', ['return', 'pos_return'])
                        ->where('status', 'completed'))
                    ->sum(DB::raw('ABS(quantity)'));
                $remaining = max(0, abs((float) $originalItem->quantity) - $returnedQuantity);
                $quantity = (float) $item['quantity'];
                if ($quantity > $remaining + 0.00001) {
                    throw ValidationException::withMessages(['items' => "الكمية المرتجعة من {$originalItem->product_id} تتجاوز المتبقي من الفاتورة."]);
                }

                $price = (float) $originalItem->unit_price;
                if (isset($item['price']) && abs((float) $item['price'] - $price) > 0.005 && ! $user->hasPermission('pos.price.edit')) {
                    throw ValidationException::withMessages(['items' => 'لا يمكن تغيير سعر صنف في المرتجع دون صلاحية تعديل السعر.']);
                }
                $lineTotal = round($quantity * $price, 2);
                $stock = BranchProduct::query()->where('tenant_id', $tenantId)
                    ->where('branch_id', $validated['branch_id'])->where('product_id', $originalItem->product_id)
                    ->lockForUpdate()->firstOrFail();
                $lines[] = compact('originalItem', 'quantity', 'price', 'lineTotal', 'stock');
                $subtotal += $lineTotal;
            }

            $subtotal = round($subtotal, 2);
            $ratio = (float) $originalOrder->subtotal > 0 ? $subtotal / (float) $originalOrder->subtotal : 0;
            $tax = round((float) $originalOrder->tax_amount * $ratio, 2);
            $discount = round((float) $originalOrder->discount * $ratio, 2);
            $total = round($subtotal + $tax - $discount, 2);
            $alreadyRefunded = abs((float) $originalOrder->returns()->where('status', 'completed')->sum('total'));
            $total = min($total, max(0, round((float) $originalOrder->total - $alreadyRefunded, 2)));
            $discount = round($subtotal + $tax - $total, 2);
            foreach (['subtotal' => $subtotal, 'total_amount' => $total] as $field => $computed) {
                if (isset($validated[$field]) && abs((float) $validated[$field] - $computed) > 0.02) {
                    throw ValidationException::withMessages([$field => 'إجمالي المرتجع لا يطابق أصناف الفاتورة الأصلية.']);
                }
            }
            if ((float) ($validated['tax_amount'] ?? 0) !== 0.0 || (float) ($validated['discount'] ?? 0) !== 0.0) {
                throw ValidationException::withMessages(['total_amount' => 'المرتجع يعكس أسعار الفاتورة الأصلية دون إضافة ضريبة أو خصم.']);
            }
            $totalCost = collect($lines)->sum(fn ($line) => (float) $line['originalItem']->cost_price * $line['quantity']);
            $originalPaid = $originalOrder->payment_status === 'paid'
                ? (float) $originalOrder->total : (float) $originalOrder->paid_amount;
            $previousRefunds = abs((float) $originalOrder->returns()->where('status', 'completed')->sum('paid_amount'));
            $previousCredit = max(0, $alreadyRefunded - $previousRefunds);
            $outstanding = max(0, (float) $originalOrder->total - $originalPaid - $previousCredit);
            $credit = min($total, $outstanding);
            $cashRefund = round($total - $credit, 2);
            if ($credit > 0 && $originalOrder->customer_id) {
                Party::query()->where('tenant_id', $tenantId)->whereKey($originalOrder->customer_id)
                    ->lockForUpdate()->firstOrFail()->decrement('current_balance', $credit);
            }

            $returnOrder = Order::create([
                'tenant_id' => $tenantId,
                'branch_id' => $validated['branch_id'],
                'shift_id' => $shift?->id,
                'created_by' => $user->id,
                'customer_id' => $originalOrder->customer_id,
                'original_order_id' => $originalOrder->id,
                'invoice_number' => 'RET-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
                'type' => 'return',
                'status' => 'completed',
                'subtotal' => -abs($subtotal),
                'tax_amount' => -abs($tax),
                'discount' => -abs($discount),
                'total' => -abs($total),
                'total_cost' => -abs($totalCost),
                'total_profit' => round(-$total + $totalCost, 2),
                'paid_amount' => -abs($cashRefund),
                'payment_status' => 'refunded',
                'notes' => $validated['reason'] ?? 'مرتجع مبيعات',
            ]);

            foreach ($lines as $line) {
                OrderItem::create([
                    'tenant_id' => $tenantId,
                    'order_id' => $returnOrder->id,
                    'original_order_item_id' => $line['originalItem']->id,
                    'product_id' => $line['originalItem']->product_id,
                    'quantity' => -$line['quantity'],
                    'unit_price' => $line['price'],
                    'total_price' => -$line['lineTotal'],
                    'cost_price' => $line['originalItem']->cost_price,
                    'total_cost' => -(float) $line['originalItem']->cost_price * $line['quantity'],
                ]);
                StockMovementContext::forDocument($returnOrder, fn () => $line['stock']->increment('stock_quantity', $line['quantity']));
            }

            if ($cashRefund > 0) {
                Payment::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $validated['branch_id'],
                    'shift_id' => $shift?->id,
                    'created_by' => $user->id,
                    'type' => 'payment',
                    'voucher_number' => 'VCH-RET-'.time().'-'.rand(100, 999),
                    'amount' => $cashRefund,
                    'payment_method' => 'cash',
                    'payment_date' => now(),
                    'order_id' => $returnOrder->id,
                ]);
            }

            return $returnOrder->load('items', 'branch', 'originalOrder');
        });

        return response()->json([
            'message' => 'تم تسجيل المرتجع بنجاح',
            'return_order' => $returnOrder,
        ], 201);
    }
}
