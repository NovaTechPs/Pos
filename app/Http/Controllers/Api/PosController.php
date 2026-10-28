<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Services\ApiShiftLedger;
use App\Services\StockMovementContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function held(Request $request): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $query = Order::with('items.product')->where('tenant_id', $tenantId)
            ->where('status', 'pending')->where('type', 'pos');
        if ($user->branch_id && ! $user->isTenantOwner()) {
            $query->where('branch_id', $user->branch_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        return response()->json(['orders' => $query->latest()->get()]);
    }

    public function salesOrders(Request $request): JsonResponse
    {
        return $this->orderList($request, 'pos');
    }

    public function wholesaleOrders(Request $request): JsonResponse
    {
        return $this->orderList($request, 'wholesale');
    }

    private function orderList(Request $request, string $type): JsonResponse
    {
        $user = $request->user();
        $orders = Order::query()->with(['items.product', 'party', 'branch'])
            ->where('tenant_id', $user->tenant_id)
            ->where('type', $type)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($inner) use ($request): void {
                $search = '%'.$request->string('search').'%';
                $inner->where('invoice_number', 'like', $search)->orWhere('customer_name', 'like', $search);
            }))
            ->latest('created_at')->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json(['orders' => $orders]);
    }

    public function hold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'held_order_id' => ['nullable', 'integer'],
            'branch_id' => ['required', 'integer'], 'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'], 'items.*.price' => ['required', 'numeric', 'min:0'],
            'subtotal' => ['required', 'numeric', 'min:0'], 'tax_amount' => ['nullable', 'numeric', 'in:0'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'total_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $branchId = (int) $validated['branch_id'];
        $order = DB::transaction(function () use ($validated, $user, $tenantId, $branchId) {
            $held = ! empty($validated['held_order_id'])
                ? Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                    ->where('status', 'pending')->where('type', 'pos')->lockForUpdate()
                    ->whereKey((int) $validated['held_order_id'])->firstOrFail() : null;
            $customer = null;
            if (! empty($validated['customer_id'])) {
                $customer = Party::query()->where('tenant_id', $tenantId)
                    ->whereIn('type', ['customer', 'both'])->where('is_active', true)
                    ->lockForUpdate()->whereKey((int) $validated['customer_id'])->firstOrFail();
            }

            $subtotal = 0.0;
            $items = [];
            foreach ($validated['items'] as $item) {
                $product = Product::query()->where('tenant_id', $tenantId)->whereKey((int) $item['product_id'])->firstOrFail();
                $branchProduct = BranchProduct::query()->where('tenant_id', $tenantId)
                    ->where('branch_id', $branchId)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                $price = (float) $item['price'];
                $pricingBranchId = $customer?->branch_id ?: $branchId;
                $pricing = $pricingBranchId === $branchId ? $branchProduct
                    : BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $pricingBranchId)
                        ->where('product_id', $product->id)->firstOrFail();
                if (abs($price - (float) $pricing->retail_price) > 0.005 && ! $user->hasPermission('pos.price.edit')) {
                    throw ValidationException::withMessages(['items' => "ليس لديك صلاحية تغيير سعر {$product->name}."]);
                }
                $subtotal += round((float) $item['quantity'] * $price, 2);
                $items[] = [$item, $product, $price];
            }
            $subtotal = round($subtotal, 2);
            if (abs($subtotal - (float) $validated['subtotal']) > 0.02) {
                throw ValidationException::withMessages(['subtotal' => 'مجموع الفاتورة لا يطابق تفاصيل الأصناف.']);
            }
            $tax = 0.0;
            $discount = min($subtotal, round((float) ($validated['discount'] ?? 0), 2));
            if ($discount > 0 && ! $user->hasPermission('pos.discount.use')) {
                throw ValidationException::withMessages(['discount' => 'ليس لديك صلاحية تطبيق خصم.']);
            }
            $total = round($subtotal + $tax - $discount, 2);
            if (abs($total - (float) $validated['total_amount']) > 0.02) {
                throw ValidationException::withMessages(['total_amount' => 'إجمالي الفاتورة لا يطابق تفاصيل الأصناف.']);
            }

            $order = $held ?? new Order;
            $order->fill([
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'created_by' => $user->id,
                'customer_id' => $customer?->id, 'invoice_number' => $held->invoice_number ?? 'HOLD-'.str()->uuid(),
                'type' => 'pos', 'source' => 'api', 'sale_kind' => 'retail', 'status' => 'pending', 'subtotal' => $subtotal,
                'tax_amount' => $tax, 'discount' => $discount,
                'total' => $total, 'paid_amount' => 0, 'payment_status' => 'unpaid', 'notes' => $validated['notes'] ?? null,
            ]);
            $order->save();
            if ($held) {
                $order->items()->delete();
            }
            foreach ($items as [$item, $product, $price]) {
                $cost = (float) $product->cost_price;
                OrderItem::create(['tenant_id' => $tenantId, 'order_id' => $order->id, 'product_id' => $product->id,
                    'quantity' => $item['quantity'], 'unit_price' => $price, 'total_price' => $item['quantity'] * $price,
                    'cost_price' => $cost, 'total_cost' => $cost * $item['quantity']]);
            }

            return $order->load('items.product');
        });

        return response()->json(['message' => 'تم حفظ الفاتورة المعلقة', 'order' => $order], 201);
    }

    public function deleteHeld(Request $request, Order $order): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        abort_unless($order->status === 'pending' && $order->type === 'pos' && $order->tenant_id === $user->tenant_id, 404);
        abort_unless(! $user->branch_id || $user->isTenantOwner() || $order->branch_id === $user->branch_id, 404);
        $order->delete();

        return response()->json(['message' => 'تم حذف الفاتورة المعلقة']);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;

        $branchId = $request->integer('branch_id') ?: $user->branch_id;
        if ($request->filled('customer_id')) {
            $customer = Party::query()->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'both'])->findOrFail($request->integer('customer_id'));
            if ($customer->branch_id && $user->branch_id && ! $user->isTenantOwner()) {
                abort_unless((int) $customer->branch_id === (int) $user->branch_id, 404);
            }
            $branchId = $customer->branch_id ?: $branchId;
        }
        abort_unless(! $branchId || Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->exists(), 404);
        $categories = Category::where('tenant_id', $tenantId)
            ->with(['products' => function ($query) use ($tenantId, $branchId) {
                $query->where('tenant_id', $tenantId)
                    ->when($branchId, fn ($productQuery) => $productQuery->whereHas('branches', fn ($branchQuery) => $branchQuery->whereKey($branchId)))
                    ->with(['barcodes', 'branches' => fn ($branchQuery) => $branchId ? $branchQuery->whereKey($branchId) : $branchQuery]);
            }])->get()
            ->toArray();

        $uncategorizedProducts = Product::where('tenant_id', $tenantId)
            ->whereNull('category_id')
            ->when($branchId, fn ($query) => $query->whereHas('branches', fn ($branchQuery) => $branchQuery->whereKey($branchId)))
            ->with(['barcodes', 'branches' => fn ($branchQuery) => $branchId ? $branchQuery->whereKey($branchId) : $branchQuery])
            ->get();

        if ($uncategorizedProducts->isNotEmpty()) {
            $categories[] = [
                'id' => 0,
                'name' => 'بدون تصنيف',
                'products' => $uncategorizedProducts->toArray(),
            ];
        }

        foreach ($categories as &$category) {
            foreach ($category['products'] as &$product) {
                $product['pricing_branch_id'] = $branchId;
            }
            unset($product);
        }
        unset($category);

        return response()->json([
            'categories' => $categories,
        ]);
    }

    public function wholesaleProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $branchId = (int) $validated['branch_id'];
        abort_unless(Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->exists(), 404);
        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'category',
                'barcodes',
                'branchProducts' => fn ($query) => $query->where('branch_id', $branchId),
            ])
            ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $branchId))
            ->get()
            ->map(function (Product $product) use ($branchId): array {
                $branchProduct = $product->branchProducts->firstWhere('branch_id', $branchId);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $product->barcodes->first()?->barcode,
                    'barcodes' => $product->barcodes->pluck('barcode')->all(),
                    'category_id' => $product->category_id,
                    'category_name' => $product->category->name ?? 'بدون تصنيف',
                    'price' => (float) (($branchProduct->wholesale_price ?? 0) > 0 ? $branchProduct->wholesale_price : ($branchProduct->retail_price ?? 0)),
                    'wholesale_price' => (float) ($branchProduct->wholesale_price ?? 0),
                    'retail_price' => (float) ($branchProduct->retail_price ?? 0),
                    'min_wholesale_quantity' => (float) ($branchProduct->min_wholesale_quantity ?? 1),
                    'stock_quantity' => (float) ($branchProduct->stock_quantity ?? 0),
                ];
            })->values();

        return response()->json(['products' => $products]);
    }

    public function storeWholesaleOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:parties,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
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
            foreach ($validated['items'] as $item) {
                $product = Product::query()->where('tenant_id', $tenantId)->whereKey((int) $item['product_id'])->firstOrFail();
                $stockRows = BranchProduct::query()->where('tenant_id', $tenantId)
                    ->where('product_id', $product->id)
                    ->when(! $unifiedStock, fn ($query) => $query->where('branch_id', $branchId))
                    ->lockForUpdate()->get();
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
                $price = (float) ($branchProduct->wholesale_price > 0 ? $branchProduct->wholesale_price : $branchProduct->retail_price);
                $cost = (float) $product->cost_price;
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
            $total = max(0, round($subtotal - $discount, 2));
            $paid = min($total, round((float) $validated['paid_amount'], 2));
            if ((float) $validated['paid_amount'] > $total + 0.005) {
                throw ValidationException::withMessages(['paid_amount' => 'المبلغ المدفوع يتجاوز إجمالي الفاتورة.']);
            }
            if ($paid < $total && ! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'اختر عميلاً لتسجيل المبلغ المتبقي على الحساب.']);
            }
            $order = Order::create([
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'created_by' => $user->id, 'shift_id' => $shift?->id,
                'customer_id' => $customer?->id, 'customer_name' => $customer->name ?? 'زبون جملة عابر',
                'customer_phone' => $customer?->phone,
                'invoice_number' => 'W-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'type' => 'wholesale', 'source' => 'api', 'sale_kind' => 'wholesale', 'status' => 'completed', 'subtotal' => round($subtotal, 2),
                'discount_type' => $discountType, 'discount_rate' => $discountRate, 'discount' => $discount,
                'total' => $total, 'total_cost' => round($totalCost, 2),
                'total_profit' => round($total - $totalCost, 2), 'paid_amount' => $paid,
                'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'notes' => $validated['notes'] ?? null,
            ]);

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

            return $order->load('items.product', 'party');
        });

        return response()->json(['message' => 'تم حفظ فاتورة الجملة', 'order' => $order], 201);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'held_order_id' => ['nullable', 'integer'],
            'branch_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'in:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,card,bank_transfer,cheque'],
        ]);

        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $branchId = (int) $validated['branch_id'];
        $allowNegativeStock = TenantSetting::getBool($tenantId, 'allow_negative_stock', false);
        $unifiedStock = TenantSetting::getBool($tenantId, 'unified_stock', false);

        $order = DB::transaction(function () use ($validated, $user, $tenantId, $branchId, $allowNegativeStock, $unifiedStock): Order {
            $shift = ApiShiftLedger::activeForWrite($tenantId, $branchId, $user->id);
            $held = ! empty($validated['held_order_id'])
                ? Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                    ->where('status', 'pending')->where('type', 'pos')->lockForUpdate()
                    ->whereKey((int) $validated['held_order_id'])->firstOrFail() : null;
            $customer = null;
            if (! empty($validated['customer_id'])) {
                $customer = Party::query()->where('tenant_id', $tenantId)
                    ->whereIn('type', ['customer', 'both'])->where('is_active', true)
                    ->lockForUpdate()->whereKey((int) $validated['customer_id'])->firstOrFail();
            }

            $lines = [];
            $subtotal = 0.0;
            $totalCost = 0.0;
            foreach ($validated['items'] as $item) {
                $product = Product::query()->where('tenant_id', $tenantId)->whereKey((int) $item['product_id'])->firstOrFail();
                $stockRows = BranchProduct::query()->where('tenant_id', $tenantId)
                    ->where('product_id', $product->id)
                    ->when(! $unifiedStock, fn ($query) => $query->where('branch_id', $branchId))
                    ->lockForUpdate()->get();
                $branchProduct = $stockRows->firstWhere('branch_id', $branchId);
                if (! $branchProduct) {
                    throw ValidationException::withMessages(['items' => "المنتج {$product->name} غير مرتبط بالفرع."]);
                }

                $quantity = (float) $item['quantity'];
                $available = (float) $stockRows->sum('stock_quantity');
                if (! $allowNegativeStock && $quantity > $available) {
                    throw ValidationException::withMessages(['items' => "الكمية المتوفرة من {$product->name} هي {$available}."]);
                }

                $priceBranchId = $customer?->branch_id ?: $branchId;
                $customerBranchProduct = $priceBranchId === $branchId
                    ? $branchProduct
                    : BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $priceBranchId)
                        ->where('product_id', $product->id)->first();
                if (! $customerBranchProduct) {
                    throw ValidationException::withMessages(['items' => "المنتج {$product->name} غير مسعّر في فرع العميل."]);
                }
                $standardPrice = (float) $customerBranchProduct->retail_price;
                $price = (float) ($item['price'] ?? $standardPrice);
                if (abs($price - $standardPrice) > 0.005 && ! $user->hasPermission('pos.price.edit')) {
                    throw ValidationException::withMessages(['items' => "ليس لديك صلاحية تغيير سعر {$product->name}."]);
                }

                $lineTotal = round($quantity * $price, 2);
                $subtotal += $lineTotal;
                $cost = (float) $product->cost_price;
                $totalCost += $cost * $quantity;
                $lines[] = compact('product', 'quantity', 'price', 'lineTotal', 'cost', 'stockRows');
            }

            $subtotal = round($subtotal, 2);
            $tax = round((float) ($validated['tax_amount'] ?? 0), 2);
            $discount = round((float) ($validated['discount'] ?? 0), 2);
            if ($discount > 0 && ! $user->hasPermission('pos.discount.use')) {
                throw ValidationException::withMessages(['discount' => 'ليس لديك صلاحية تطبيق خصم.']);
            }
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'قيمة الخصم تتجاوز قيمة الأصناف.']);
            }
            $total = round($subtotal + $tax - $discount, 2);

            foreach (['subtotal' => $subtotal, 'total_amount' => $total] as $field => $computed) {
                if (isset($validated[$field]) && abs((float) $validated[$field] - $computed) > 0.02) {
                    throw ValidationException::withMessages([$field => 'إجمالي الفاتورة لا يطابق تفاصيل الأصناف.']);
                }
            }

            $paymentMethod = $validated['payment_method'] ?? 'cash';

            $order = $held ?? new Order;
            $order->fill([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'shift_id' => $shift?->id,
                'created_by' => $user->id,
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name,
                'customer_phone' => $customer?->phone,
                'invoice_number' => 'INV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
                'type' => 'pos', 'source' => 'api', 'sale_kind' => 'retail',
                'status' => 'completed',
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'discount' => $discount,
                'total' => $total,
                'paid_amount' => $total,
                'payment_status' => 'paid',
                'total_cost' => round($totalCost, 2),
                'total_profit' => round($total - $totalCost, 2),
            ]);
            $order->save();
            if ($held) {
                $order->items()->delete();
            }

            foreach ($lines as $line) {
                OrderItem::create([
                    'tenant_id' => $tenantId,
                    'order_id' => $order->id,
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['price'],
                    'total_price' => $line['lineTotal'],
                    'cost_price' => $line['cost'],
                    'total_cost' => $line['cost'] * $line['quantity'],
                ]);

                $remaining = $line['quantity'];
                $stockRows = $line['stockRows']->sortByDesc(fn ($row) => (int) $row->branch_id === $branchId)->values();
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

            Payment::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'shift_id' => $shift?->id,
                'created_by' => $user->id,
                'type' => 'receipt',
                'voucher_number' => 'VCH-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
                'amount' => $total,
                'payment_method' => in_array($paymentMethod, ['cash', 'card', 'bank_transfer', 'cheque']) ? $paymentMethod : 'cash',
                'payment_date' => now(),
                'order_id' => $order->id,
            ]);

            return $order->load('items.product', 'branch', 'party');
        });

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order,
        ], 201);
    }
}
