<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Services\ApiShiftLedger;
use App\Services\StockMovementContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WholesaleWorkspaceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:parties,id'],
            'invoice_id' => ['nullable', 'integer'],
            'confirm_below_cost' => ['sometimes', 'boolean'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'discount_type' => ['nullable', 'in:fixed,percentage'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paid_amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:cash,card,bank_transfer,cheque'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user() ?? auth()->user();
        $tenantId = (int) ($user->tenant_id);
        $branchId = (int) $validated['branch_id'];
        abort_unless(Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->exists(), 404);
        $allowNegativeStock = TenantSetting::getBool($tenantId, 'allow_negative_stock', false);
        $unifiedStock = TenantSetting::getBool($tenantId, 'unified_stock', false);

        $order = DB::transaction(function () use ($validated, $user, $tenantId, $branchId, $allowNegativeStock, $unifiedStock): Order {
            $shift = ApiShiftLedger::activeForWrite($tenantId, $branchId, $user->id);
            $editing = null;
            if (! empty($validated['invoice_id'])) {
                abort_unless($user->hasPermission('wholesale_sales.edit'), 403);
                abort_if(config('branch-sync.mode') === 'edge', 422, 'التعديل متاح من النظام المركزي فقط.');
                $editing = Order::where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('type', 'wholesale')->where('status', 'completed')->lockForUpdate()->whereKey($validated['invoice_id'])->firstOrFail();
                $this->reverse($editing);
            }
            $customer = null;
            if (! empty($validated['customer_id'])) {
                $customer = Party::query()->where('tenant_id', $tenantId)
                    ->whereIn('type', ['customer', 'both'])->where('is_active', true)
                    ->lockForUpdate()->whereKey((int) $validated['customer_id'])->firstOrFail();
            }
            if ($customer?->branch_id && $user->branch_id && ! $user->isTenantOwner()) {
                abort_unless((int) $customer->branch_id === (int) $user->branch_id, 404);
            }

            $items = [];
            $subtotal = 0.0;
            $totalCost = 0.0;
            $productIds = array_column($validated['items'], 'product_id');
            $products = Product::where('tenant_id', $tenantId)->whereIn('id', $productIds)->get()->keyBy('id');
            $stocks = BranchProduct::where('tenant_id', $tenantId)->whereIn('product_id', $productIds)
                ->when(! $unifiedStock, fn ($query) => $query->where('branch_id', $branchId))
                ->orderBy('product_id')->orderBy('branch_id')->lockForUpdate()->get()->groupBy('product_id');
            foreach ($validated['items'] as $item) {
                $product = $products->get((int) $item['product_id']);
                abort_unless($product !== null, 404);
                $stockRows = $stocks->get($product->id, collect());
                $branchProduct = $stockRows->firstWhere('branch_id', $branchId);
                if (! $branchProduct) {
                    throw ValidationException::withMessages(['items' => "المنتج {$product->name} غير مرتبط بالفرع."]);
                }
                $quantity = (float) $item['quantity'];
                $minimum = (float) ($branchProduct->min_wholesale_quantity ?: 1);
                if ($quantity < $minimum) {
                    throw ValidationException::withMessages(['items' => "الحد الأدنى للبيع بالجملة من {$product->name} هو {$minimum}."]);
                }
                $available = (float) $stockRows->sum('stock_quantity');
                if (! $allowNegativeStock && $quantity > $available) {
                    throw ValidationException::withMessages(['items' => "الكمية المتوفرة من {$product->name} هي {$available}."]);
                }
                $price = (float) ($item['price'] ?? ($branchProduct->wholesale_price > 0 ? $branchProduct->wholesale_price : $branchProduct->retail_price));
                $cost = (float) $product->cost_price;
                if ($price < $cost && ! $user->hasPermission('pos.sell_below_cost') && ! ($validated['confirm_below_cost'] ?? false)) {
                    throw ValidationException::withMessages(['items' => 'أكد البيع بأقل من التكلفة قبل المتابعة.']);
                }
                $lineTotal = round($price * $quantity, 2);
                $subtotal += $lineTotal;
                $totalCost += $cost * $quantity;
                $items[] = compact('product', 'quantity', 'price', 'cost', 'lineTotal', 'stockRows');
            }

            $discountType = $validated['discount_type'] ?? 'fixed';
            $discountRate = $discountType === 'percentage' ? (float) ($validated['discount_rate'] ?? 0) : 0.0;
            $discount = $discountType === 'percentage'
                ? round($subtotal * $discountRate / 100, 2)
                : min($subtotal, (float) ($validated['discount_amount'] ?? 0));
            if ($discountType === 'fixed' && (float) ($validated['discount_amount'] ?? 0) > $subtotal) {
                throw ValidationException::withMessages(['discount_amount' => 'الخصم يتجاوز قيمة الفاتورة.']);
            }
            $total = max(0, round($subtotal - $discount, 2));
            $paid = min($total, round((float) $validated['paid_amount'], 2));
            if ((float) $validated['paid_amount'] > $total + 0.005) {
                throw ValidationException::withMessages(['paid_amount' => 'المبلغ المدفوع يتجاوز إجمالي الفاتورة.']);
            }
            if ($paid < $total && ! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'اختر عميلاً لتسجيل المبلغ المتبقي على الحساب.']);
            }
            $previousBalance = (float) ($customer->current_balance ?? 0);
            $order = $editing ?? new Order;
            $order->fill([
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'created_by' => $user->id, 'shift_id' => $shift?->id,
                'customer_id' => $customer?->id, 'customer_name' => $customer->name ?? 'زبون جملة عابر',
                'customer_phone' => $customer?->phone,
                'invoice_number' => $editing->invoice_number ?? 'W-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'type' => 'wholesale', 'source' => 'api', 'sale_kind' => 'wholesale', 'status' => 'completed', 'subtotal' => round($subtotal, 2),
                'discount_type' => $discountType, 'discount_rate' => $discountRate, 'discount' => $discount,
                'total' => $total, 'total_cost' => round($totalCost, 2),
                'total_profit' => round($total - $totalCost, 2), 'paid_amount' => $paid,
                'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'notes' => $validated['notes'] ?? null,
            ]);
            $order->save();

            foreach ($items as $item) {
                OrderItem::create([
                    'tenant_id' => $tenantId, 'order_id' => $order->id, 'product_id' => $item['product']->id,
                    'quantity' => $item['quantity'], 'unit_price' => $item['price'],
                    'total_price' => $item['lineTotal'], 'cost_price' => $item['cost'],
                    'total_cost' => $item['cost'] * $item['quantity'], 'discount' => 0,
                ]);
                $remaining = $item['quantity'];
                $stockRows = $item['stockRows']->sortByDesc(fn ($row) => (int) $row->branch_id === $branchId)->values();
                foreach ($stockRows as $stockRow) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min(max(0, (float) $stockRow->stock_quantity), $remaining);
                    if ($take > 0) {
                        StockMovementContext::forDocument($order, fn () => $stockRow->decrement('stock_quantity', $take));
                        $remaining -= $take;
                    }
                }
                if ($remaining > 0 && $allowNegativeStock) {
                    StockMovementContext::forDocument($order, fn () => ($stockRows->firstWhere('branch_id', $branchId) ?? $stockRows->first())->decrement('stock_quantity', $remaining));
                }
            }

            if ($paid > 0) {
                Payment::create([
                    'tenant_id' => $tenantId, 'branch_id' => $branchId, 'created_by' => $user->id, 'shift_id' => $shift?->id,
                    'type' => 'receipt', 'voucher_number' => 'RCV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                    'party_id' => $customer?->id, 'amount' => $paid, 'payment_method' => $validated['payment_method'],
                    'order_id' => $order->id, 'notes' => 'دفعة على فاتورة الجملة '.$order->invoice_number,
                    'payment_date' => now(),
                ]);
            }
            if ($customer) {
                $customer->increment('current_balance', round($total - $paid, 2));
            }

            return $order->load(['items.product', 'party', 'branch'])->setAttribute('payment_method', $validated['payment_method'])->setAttribute('previous_balance', $previousBalance)->setAttribute('current_balance', $customer?->fresh()->current_balance ?? 0);
        });

        return response()->json(['message' => 'تم حفظ فاتورة الجملة', 'order' => $order], 201);
    }

    /** @return Builder<Order> */
    private function orders(Request $request): Builder
    {
        return Order::query()->where('tenant_id', $request->user()->tenant_id)
            ->where('branch_id', $request->integer('branch_id'))->where('type', 'wholesale')->where('status', 'completed');
    }

    public function invoice(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => ['required', 'integer'], 'id' => ['nullable', 'integer'],
            'before' => ['nullable', 'integer'], 'after' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = $this->orders($request);
        if ($request->filled('id')) {
            $query->whereKey($request->integer('id'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($q) => $q->where('invoice_number', $search)->orWhere('id', ctype_digit($search) ? (int) $search : 0));
        }
        if ($request->filled('before')) {
            $query->where('id', '<', $request->integer('before'));
        }
        if ($request->filled('after')) {
            $query->where('id', '>', $request->integer('after'))->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }
        $order = $query->with('items.product', 'party', 'branch')->firstOrFail();
        $order->setAttribute('payment_method', Payment::where('tenant_id', $order->tenant_id)->where('order_id', $order->id)->latest('id')->value('payment_method') ?? 'cash');

        return response()->json(['order' => $order,
            'previous_id' => $this->orders($request)->where('id', '<', $order->id)->max('id'),
            'next_id' => $this->orders($request)->where('id', '>', $order->id)->min('id')]);
    }

    public function products(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => ['required', 'integer']]);
        $response = app(PosController::class)->wholesaleProducts($request)->getData(true);
        $tenantId = $request->user()->tenant_id;
        $products = Product::where('tenant_id', $tenantId)->whereIn('id', array_column($response['products'], 'id'))->get()->keyBy('id');
        $unified = TenantSetting::getBool($tenantId, 'unified_stock', false);
        $stocks = $unified ? BranchProduct::where('tenant_id', $tenantId)->whereIn('product_id', $products->keys())
            ->selectRaw('product_id, SUM(stock_quantity) as available')->groupBy('product_id')->pluck('available', 'product_id') : collect();
        foreach ($response['products'] as &$product) {
            $product['cost_price'] = (float) $products[$product['id']]->cost_price;
            $product['image'] = $products[$product['id']]->image;
            $product['product_number'] = $products[$product['id']]->product_number;
            if ($unified) {
                $product['stock_quantity'] = (float) $stocks->get($product['id'], 0);
            }
        }
        unset($product);

        return response()->json($response + ['workspace_version' => 1, 'allow_negative_stock' => TenantSetting::getBool($tenantId, 'allow_negative_stock', false),
            'can_sell_below_cost' => $request->user()->hasPermission('pos.sell_below_cost')]);
    }

    public function priceHistory(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'product_id' => ['required', 'integer'], 'customer_id' => ['required', 'integer']]);
        $tenantId = $request->user()->tenant_id;
        Product::where('tenant_id', $tenantId)->whereKey($data['product_id'])->firstOrFail();
        $customer = Party::where('tenant_id', $tenantId)->whereIn('type', ['customer', 'both'])->whereKey($data['customer_id'])->firstOrFail();
        abort_unless(! $request->user()->branch_id || $request->user()->isTenantOwner() || ! $customer->branch_id || $customer->branch_id === $request->user()->branch_id, 404);
        $items = OrderItem::where('tenant_id', $tenantId)->where('product_id', $data['product_id'])
            ->whereHas('order', fn ($q) => $q->where('tenant_id', $tenantId)->where('branch_id', $data['branch_id'])->where('customer_id', $customer->id)->where('type', 'wholesale')->where('status', 'completed'))
            ->latest('created_at')->limit(10)->get(['unit_price', 'quantity', 'created_at', 'order_id']);

        return response()->json(['history' => $items]);
    }

    private function reverse(Order $order): void
    {
        if (Order::where('tenant_id', $order->tenant_id)->where('original_order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['invoice_id' => 'الفاتورة مرتبطة بمرتجع ولا يمكن تعديلها.']);
        }
        $movements = DB::table('stock_movements')->where('tenant_id', $order->tenant_id)->where('source_id', $order->id)
            ->whereIn('source_type', ['order', 'sale_reversal'])->selectRaw('product_id, branch_id, SUM(quantity) as effect')->groupBy('product_id', 'branch_id')->get();
        $expected = $order->items()->get()->groupBy('product_id')->map(fn ($items) => (float) $items->sum('quantity'));
        $effects = $movements->groupBy('product_id')->map(fn ($rows) => -(float) $rows->sum('effect'));
        foreach ($expected as $productId => $quantity) {
            if (abs($quantity - $effects->get($productId, 0)) > 0.0001) {
                throw ValidationException::withMessages(['invoice_id' => 'سجل مخزون الفاتورة غير مكتمل؛ راجع الإدارة قبل تعديلها.']);
            }
        }
        foreach ($movements as $movement) {
            if ((float) $movement->effect === 0.0) {
                continue;
            }
            if ((float) $movement->effect > 0 || ! $expected->has($movement->product_id)) {
                throw ValidationException::withMessages(['invoice_id' => 'تعذر مطابقة حركات مخزون الفاتورة.']);
            }
            $stock = BranchProduct::where('tenant_id', $order->tenant_id)->where('branch_id', $movement->branch_id)->where('product_id', $movement->product_id)->lockForUpdate()->firstOrFail();
            StockMovementContext::forDocument($order, fn () => $stock->increment('stock_quantity', -(float) $movement->effect), 'sale_reversal');
        }
        if ($order->customer_id) {
            Party::where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->lockForUpdate()->firstOrFail()
                ->decrement('current_balance', round((float) $order->total - (float) $order->paid_amount, 2));
        }
        foreach (Payment::where('tenant_id', $order->tenant_id)->where('order_id', $order->id)->lockForUpdate()->get() as $payment) {
            $payment->delete();
        }
        $order->items()->delete();
    }

    public function receipts(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => ['required', 'integer'], 'id' => ['nullable', 'integer'], 'before' => ['nullable', 'integer'],
            'after' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = Payment::where('tenant_id', $request->user()->tenant_id)->where('branch_id', $request->integer('branch_id'))->where('type', 'receipt')->whereNull('order_id');
        if ($request->filled('id')) {
            $query->whereKey($request->integer('id'));
        }
        if ($request->filled('before')) {
            $query->where('id', '<', $request->integer('before'));
        }
        if ($request->filled('after')) {
            $query->where('id', '>', $request->integer('after'))->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }
        if ($request->filled('search')) {
            $query->where('voucher_number', $request->string('search')->toString());
        }

        return response()->json(['voucher' => $query->with('party')->firstOrFail()]);
    }

    public function storeReceipt(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'customer_id' => ['required', 'integer'], 'receipt_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999'], 'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'in:cash,card,bank_transfer,cheque'], 'notes' => ['nullable', 'string', 'max:5000'],
            'voucher_number' => ['nullable', 'string', 'max:50']]);
        $user = $request->user();
        $voucher = DB::transaction(function () use ($data, $user): Payment {
            $shift = ApiShiftLedger::activeForWrite($user->tenant_id, $data['branch_id'], $user->id);
            $payment = null;
            if (! empty($data['receipt_id'])) {
                abort_unless($user->hasPermission('accounting.receipt.edit'), 403);
                $payment = Payment::where('tenant_id', $user->tenant_id)->where('branch_id', $data['branch_id'])->where('type', 'receipt')->whereNull('order_id')->lockForUpdate()->whereKey($data['receipt_id'])->firstOrFail();
                if ($payment->party_id) {
                    Party::where('tenant_id', $user->tenant_id)->whereKey($payment->party_id)->lockForUpdate()->firstOrFail()->increment('current_balance', (float) $payment->amount);
                }
            }
            $party = Party::where('tenant_id', $user->tenant_id)->whereIn('type', ['customer', 'both'])->where('is_active', true)->lockForUpdate()->whereKey($data['customer_id'])->firstOrFail();
            abort_unless(! $user->branch_id || $user->isTenantOwner() || ! $party->branch_id || $party->branch_id === $user->branch_id, 404);
            $previous = (float) $party->current_balance;
            $number = $data['voucher_number'] ?? $payment->voucher_number ?? 'RCP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
            if (Payment::where('tenant_id', $user->tenant_id)->where('voucher_number', $number)->when($payment, fn ($q) => $q->where('id', '!=', $payment->id))->exists()) {
                throw ValidationException::withMessages(['voucher_number' => 'رقم السند مستخدم مسبقاً.']);
            }
            $payment ??= new Payment;
            $payment->fill(['tenant_id' => $user->tenant_id, 'branch_id' => $data['branch_id'], 'created_by' => $payment->created_by ?? $user->id,
                'shift_id' => $payment->shift_id ?? $shift?->id, 'party_id' => $party->id, 'type' => 'receipt', 'voucher_number' => $number,
                'amount' => $data['amount'], 'payment_method' => $data['payment_method'], 'payment_date' => $data['payment_date'], 'notes' => $data['notes'] ?? null]);
            $payment->save();
            $party->decrement('current_balance', (float) $data['amount']);

            return $payment->load('party')->setAttribute('previous_balance', $previous)->setAttribute('current_balance', $party->fresh()->current_balance);
        });

        return response()->json(['voucher' => $voucher], 201);
    }
}
