<?php

use Livewire\Component;
use Livewire\WithPagination;

use App\Models\Tenant;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination;

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    */

    public string $slug = '';

    public int $tenantId = 0;

    public ?int $branchId = null;

    /*
    |--------------------------------------------------------------------------
    | Search / Filters
    |--------------------------------------------------------------------------
    */

    public string $search = '';

    public ?int $categoryId = null;

    public string $sort = 'latest';

    public int $perPage = 12;

    /*
    |--------------------------------------------------------------------------
    | Customer
    |--------------------------------------------------------------------------
    */

    public string $customer_name = '';

    public string $customer_phone = '';

    public string $customer_address = '';

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected array $rules = [
        'customer_name' => 'required|string|max:255',
        'customer_phone' => 'required|string|max:50',
        'customer_address' => 'required|string|max:500',
    ];

    protected array $validationAttributes = [
        'customer_name' => 'الاسم الكامل',
        'customer_phone' => 'رقم الهاتف',
        'customer_address' => 'عنوان التوصيل',
    ];

    /*
    |--------------------------------------------------------------------------
    | Mount
    |--------------------------------------------------------------------------
    */

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $tenant = Tenant::query()
            ->where('domain', $slug)
            ->first();

        abort_unless($tenant, 404);

        $this->tenantId = (int) $tenant->id;

        $this->branchId = $this->resolveBranchId();
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    protected function tenant(): Tenant
    {
        return Tenant::query()
            ->findOrFail($this->tenantId);
    }

    /*
    |--------------------------------------------------------------------------
    | Branch
    |--------------------------------------------------------------------------
    */

    protected function resolveBranchId(): ?int
    {
        /*
         * Prefer the branch selected by the current session.
         */
        $sessionBranchId = session('active_branch_id');

        if ($sessionBranchId) {
            $validBranch = DB::table('branches')
                ->where('id', $sessionBranchId)
                ->where('tenant_id', $this->tenantId)
                ->exists();

            if ($validBranch) {
                return (int) $sessionBranchId;
            }
        }

        /*
         * Otherwise use the first branch belonging
         * to this tenant.
         */
        $branchId = DB::table('branches')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('id')
            ->value('id');

        return $branchId
            ? (int) $branchId
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Search / Filter Updates
    |--------------------------------------------------------------------------
    */

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';

        $this->categoryId = null;

        $this->sort = 'latest';

        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Cart Key
    |--------------------------------------------------------------------------
    */

    protected function cartKey(): string
    {
        return 'online_cart_' . $this->tenantId;
    }

    /*
    |--------------------------------------------------------------------------
    | Cart
    |--------------------------------------------------------------------------
    */

    protected function getCart(): array
    {
        return session()->get(
            $this->cartKey(),
            []
        );
    }

    protected function saveCart(array $cart): void
    {
        if (empty($cart)) {
            session()->forget(
                $this->cartKey()
            );

            return;
        }

        session()->put(
            $this->cartKey(),
            $cart
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Product Image
    |--------------------------------------------------------------------------
    */

    protected function productImage(?string $image): string
    {
        if (!$image) {
            return asset(
                'images/default-product.png'
            );
        }

        if (
            Str::startsWith(
                $image,
                ['http://', 'https://']
            )
        ) {
            return $image;
        }

        return Storage::url($image);
    }

    /*
    |--------------------------------------------------------------------------
    | Product Price
    |--------------------------------------------------------------------------
    |
    | Priority:
    |
    | 1. products.website_price
    | 2. branch_products.retail_price
    |
    */

    protected function getProductPrice(
        Product $product,
        ?object $branchProduct = null
    ): float {
        if ($product->website_price !== null) {
            return round(
                (float) $product->website_price,
                2
            );
        }

        if ($branchProduct) {
            return round(
                (float) $branchProduct->retail_price,
                2
            );
        }

        return 0.00;
    }

    /*
    |--------------------------------------------------------------------------
    | Product Cost
    |--------------------------------------------------------------------------
    */

    protected function getProductCost(
        Product $product
    ): float {
        return round(
            (float) $product->cost_price,
            2
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Get Branch Product
    |--------------------------------------------------------------------------
    */

    protected function getBranchProduct(
        int $productId
    ): ?object {
        if (!$this->branchId) {
            return null;
        }

        return DB::table('branch_products')
            ->where('tenant_id', $this->tenantId)
            ->where('branch_id', $this->branchId)
            ->where('product_id', $productId)
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Add To Cart
    |--------------------------------------------------------------------------
    */

    public function addToCart(int $productId): void
    {
        /*
         * Make sure branch is still valid.
         */
        $this->branchId = $this->resolveBranchId();

        if (!$this->branchId) {
            $this->dispatch(
                'toast',
                message: 'لا يوجد فرع متاح لهذا المتجر.'
            );

            return;
        }

        /*
         * Load product.
         */
        $product = Product::query()
            ->where('tenant_id', $this->tenantId)
            ->where('show_in_website', true)
            ->find($productId);

        if (!$product) {
            $this->dispatch(
                'toast',
                message: 'هذا المنتج غير متاح حاليًا.'
            );

            return;
        }

        /*
         * Load branch data.
         */
        $branchProduct = $this->getBranchProduct(
            $product->id
        );

        /*
         * Product must have branch information
         * because stock belongs to branch_products.
         */
        if (!$branchProduct) {
            $this->dispatch(
                'toast',
                message: 'هذا المنتج غير مرتبط بالفرع الحالي.'
            );

            return;
        }

        /*
         * Current stock.
         */
        $stock = (float) $branchProduct->stock_quantity;

        if ($stock <= 0) {
            $this->dispatch(
                'toast',
                message: 'هذا المنتج غير متوفر حاليًا.'
            );

            return;
        }

        /*
         * Current selling price.
         */
        $price = $this->getProductPrice(
            $product,
            $branchProduct
        );

        if ($price < 0) {
            $this->dispatch(
                'toast',
                message: 'سعر المنتج غير صالح.'
            );

            return;
        }

        /*
         * Current cost.
         */
        $cost = $this->getProductCost(
            $product
        );

        /*
         * Cart.
         */
        $cart = $this->getCart();

        if (isset($cart[$productId])) {

            $currentQuantity = (float) (
                $cart[$productId]['quantity'] ?? 0
            );

            $newQuantity = $currentQuantity + 1;

            if ($newQuantity > $stock) {
                $this->dispatch(
                    'toast',
                    message: 'لا توجد كمية إضافية من هذا المنتج.'
                );

                return;
            }

            $cart[$productId]['quantity'] =
                min(99, $newQuantity);

        } else {

            $cart[$productId] = [
                'id' => $product->id,

                'name' => $product->name,

                'image' => $this->productImage(
                    $product->image
                ),

                'price' => $price,

                'cost' => $cost,

                'quantity' => 1,
            ];
        }

        /*
         * Always refresh current data.
         */
        $cart[$productId]['name'] =
            $product->name;

        $cart[$productId]['image'] =
            $this->productImage(
                $product->image
            );

        $cart[$productId]['price'] =
            $price;

        $cart[$productId]['cost'] =
            $cost;

        $this->saveCart($cart);

        $this->dispatch(
            'toast',
            message: 'تمت إضافة المنتج إلى السلة.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Quantity
    |--------------------------------------------------------------------------
    */

    public function updateQuantity(
        int $productId,
        int|float $quantity
    ): void {
        $cart = $this->getCart();

        if (!isset($cart[$productId])) {
            return;
        }

        $quantity = (float) $quantity;

        /*
         * Remove.
         */
        if ($quantity <= 0) {
            unset($cart[$productId]);

            $this->saveCart($cart);

            return;
        }

        /*
         * Maximum quantity.
         */
        $quantity = min(
            99,
            $quantity
        );

        /*
         * Check current stock.
         */
        $branchProduct =
            $this->getBranchProduct(
                $productId
            );

        if (!$branchProduct) {
            unset($cart[$productId]);

            $this->saveCart($cart);

            $this->dispatch(
                'toast',
                message: 'المنتج لم يعد مرتبطًا بالفرع.'
            );

            return;
        }

        $stock = (float) $branchProduct->stock_quantity;

        if ($stock <= 0) {
            unset($cart[$productId]);

            $this->saveCart($cart);

            $this->dispatch(
                'toast',
                message: 'المنتج لم يعد متوفرًا.'
            );

            return;
        }

        if ($quantity > $stock) {
            $quantity = $stock;

            $this->dispatch(
                'toast',
                message: 'تم ضبط الكمية حسب المخزون المتاح.'
            );
        }

        $cart[$productId]['quantity'] =
            $quantity;

        $this->saveCart($cart);
    }

    /*
    |--------------------------------------------------------------------------
    | Remove Item
    |--------------------------------------------------------------------------
    */

    public function removeItem(
        int $productId
    ): void {
        $cart = $this->getCart();

        unset($cart[$productId]);

        $this->saveCart($cart);

        $this->dispatch(
            'toast',
            message: 'تم حذف المنتج من السلة.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Place Order
    |--------------------------------------------------------------------------
    */

    public function placeOrder()
    {
        $this->validate();

        /*
         * Resolve branch again.
         */
        $this->branchId =
            $this->resolveBranchId();

        if (!$this->branchId) {
            $this->dispatch(
                'toast',
                message: 'لا يوجد فرع متاح لإتمام الطلب.'
            );

            return;
        }

        /*
         * Read cart.
         */
        $cart = $this->getCart();

        if (empty($cart)) {
            $this->dispatch(
                'toast',
                message: 'السلة فارغة.'
            );

            return;
        }

        $tenant = $this->tenant();

        $productIds = array_map(
            'intval',
            array_keys($cart)
        );

        /*
         * Load all products at once.
         */
        $products = Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('show_in_website', true)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->isEmpty()) {
            $this->dispatch(
                'toast',
                message: 'المنتجات الموجودة في السلة لم تعد متاحة.'
            );

            return;
        }

        /*
         * Load all branch products at once.
         */
        $branchProducts = DB::table(
            'branch_products'
        )
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $this->branchId)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        /*
         * Clean cart.
         */
        $validatedCart = [];

        $subtotal = 0.00;

        $totalCost = 0.00;

        /*
         * Validate every item.
         */
        foreach ($cart as $productId => $cartItem) {

            $productId = (int) $productId;

            /*
             * Product removed or hidden.
             */
            if (!$products->has($productId)) {
                continue;
            }

            $product =
                $products->get($productId);

            /*
             * Quantity.
             */
            $quantity = (float) (
                $cartItem['quantity'] ?? 0
            );

            if ($quantity <= 0) {
                continue;
            }

            /*
             * Branch product.
             */
            $branchProduct =
                $branchProducts->get(
                    $productId
                );

            if (!$branchProduct) {
                $this->dispatch(
                    'toast',
                    message: "المنتج {$product->name} غير متوفر في الفرع."
                );

                return;
            }

            /*
             * Stock.
             */
            $stockQuantity =
                (float) $branchProduct->stock_quantity;

            if ($stockQuantity <= 0) {
                $this->dispatch(
                    'toast',
                    message: "المنتج {$product->name} غير متوفر."
                );

                return;
            }

            if ($quantity > $stockQuantity) {
                $this->dispatch(
                    'toast',
                    message: "الكمية المطلوبة من {$product->name} أكبر من المخزون المتاح."
                );

                return;
            }

            /*
             * Current price.
             */
            $unitPrice =
                $this->getProductPrice(
                    $product,
                    $branchProduct
                );

            /*
             * Current cost.
             */
            $unitCost =
                $this->getProductCost(
                    $product
                );

            /*
             * Line total.
             */
            $totalPrice =
                round(
                    $unitPrice * $quantity,
                    2
                );

            /*
             * Line cost.
             */
            $lineCost =
                round(
                    $unitCost * $quantity,
                    2
                );

            $subtotal += $totalPrice;

            $totalCost += $lineCost;

            /*
             * Clean cart item.
             */
            $validatedCart[$productId] = [
                'id' => $productId,

                'name' => $product->name,

                'image' => $this->productImage(
                    $product->image
                ),

                'price' => $unitPrice,

                'cost' => $unitCost,

                'quantity' => $quantity,
            ];
        }

        /*
         * Nothing valid.
         */
        if (empty($validatedCart)) {

            $this->saveCart([]);

            $this->dispatch(
                'toast',
                message: 'لم تعد المنتجات الموجودة في السلة متاحة.'
            );

            return;
        }

        /*
         * Totals.
         */
        $subtotal =
            round(
                $subtotal,
                2
            );

        $totalCost =
            round(
                $totalCost,
                2
            );

        $discount = 0.00;

        $total =
            round(
                max(
                    0,
                    $subtotal - $discount
                ),
                2
            );

        $totalProfit =
            round(
                $total - $totalCost,
                2
            );

        /*
         * Invoice number.
         */
        $invoiceNumber =
            'INV-ON-' .
            now()->format('YmdHis') .
            '-' .
            strtoupper(
                Str::random(5)
            );

        /*
        |--------------------------------------------------------------------------
        | Database Transaction
        |--------------------------------------------------------------------------
        */

        DB::transaction(
            function () use (
                $tenant,
                $validatedCart,
                $subtotal,
                $discount,
                $total,
                $totalCost,
                $totalProfit,
                $invoiceNumber
            ) {

                /*
                |--------------------------------------------------------------------------
                | Create Order
                |--------------------------------------------------------------------------
                */

                $order = Order::create([
                    'tenant_id' => $tenant->id,

                    'branch_id' => $this->branchId,

                    /*
                     * Guest online order.
                     */
                    'created_by' => null,

                    /*
                     * Not connected to POS shift.
                     */
                    'shift_id' => null,

                    'customer_id' => null,

                    'customer_name' =>
                        trim(
                            $this->customer_name
                        ),

                    'customer_phone' =>
                        trim(
                            $this->customer_phone
                        ),

                    'customer_address' =>
                        trim(
                            $this->customer_address
                        ),

                    'invoice_number' =>
                        $invoiceNumber,

                    'type' => 'online',

                    'status' => 'pending',

                    'subtotal' =>
                        $subtotal,

                    'tax_amount' =>
                        0.00,

                    'discount_type' =>
                        'fixed',

                    'discount_rate' =>
                        0.00,

                    'discount' =>
                        $discount,

                    'total' =>
                        $total,

                    'total_cost' =>
                        $totalCost,

                    'total_profit' =>
                        $totalProfit,

                    'paid_amount' =>
                        0.00,

                    'payment_status' =>
                        'unpaid',

                    'notes' =>
                        null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Create Order Items
                |--------------------------------------------------------------------------
                */

                foreach (
                    $validatedCart
                    as $item
                ) {

                    $quantity =
                        (float) $item['quantity'];

                    $unitPrice =
                        round(
                            (float) $item['price'],
                            2
                        );

                    $unitCost =
                        round(
                            (float) $item['cost'],
                            2
                        );

                    $totalPrice =
                        round(
                            $unitPrice *
                            $quantity,
                            2
                        );

                    $totalCostLine =
                        round(
                            $unitCost *
                            $quantity,
                            2
                        );

                    OrderItem::create([
                        'tenant_id' =>
                            $tenant->id,

                        'order_id' =>
                            $order->id,

                        'product_id' =>
                            (int) $item['id'],

                        'quantity' =>
                            $quantity,

                        'unit_price' =>
                            $unitPrice,

                        'total_price' =>
                            $totalPrice,

                        'cost_price' =>
                            $unitCost,

                        'discount' =>
                            0.00,

                        'total_cost' =>
                            $totalCostLine,
                    ]);
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | WhatsApp
        |--------------------------------------------------------------------------
        */

        $storePhone =
            preg_replace(
                '/\D+/',
                '',
                (string) $tenant->phone
            );

        if (!$storePhone) {
            $storePhone =
                '970590000000';
        }

        $message =
            "🛒 طلب جديد من المتجر الإلكتروني\n\n";

        $message .=
            "رقم الطلب: " .
            $invoiceNumber .
            "\n";

        $message .=
            "العميل: " .
            trim($this->customer_name) .
            "\n";

        $message .=
            "الهاتف: " .
            trim($this->customer_phone) .
            "\n";

        $message .=
            "العنوان: " .
            trim($this->customer_address) .
            "\n\n";

        $message .=
            "المنتجات:\n";

        foreach (
            $validatedCart
            as $item
        ) {

            $quantity =
                (float) $item['quantity'];

            $lineTotal =
                round(
                    $item['price'] *
                    $quantity,
                    2
                );

            $message .=
                "• " .
                $item['name'] .
                " × " .
                $quantity .
                " = " .
                number_format(
                    $lineTotal,
                    2
                ) .
                " ₪\n";
        }

        $message .=
            "\nالإجمالي: " .
            number_format(
                $total,
                2
            ) .
            " ₪\n";

        $message .=
            "طريقة الدفع: عند الاستلام";

        $whatsappUrl =
            'https://wa.me/' .
            $storePhone .
            '?text=' .
            urlencode(
                $message
            );

        /*
        |--------------------------------------------------------------------------
        | Clear Cart
        |--------------------------------------------------------------------------
        */

        session()->forget(
            $this->cartKey()
        );

        $this->customer_name = '';

        $this->customer_phone = '';

        $this->customer_address = '';

        /*
        |--------------------------------------------------------------------------
        | Redirect WhatsApp
        |--------------------------------------------------------------------------
        */

        return $this->redirect(
            $whatsappUrl,
            navigate: false
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        $tenant = $this->tenant();

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        $categories = Category::query()
            ->where(
                'tenant_id',
                $this->tenantId
            )
            ->orderBy('name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Products
        |--------------------------------------------------------------------------
        */

        $productsQuery =
            Product::query()
                ->where(
                    'tenant_id',
                    $this->tenantId
                )
                ->where(
                    'show_in_website',
                    true
                )
                ->with('category');

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (
            trim($this->search) !== ''
        ) {

            $search =
                trim(
                    $this->search
                );

            $productsQuery->where(
                function ($query) use (
                    $search
                ) {

                    $query
                        ->where(
                            'name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhereHas(
                            'barcodes',
                            function (
                                $barcodeQuery
                            ) use (
                                $search
                            ) {

                                $barcodeQuery->where(
                                    'barcode',
                                    'like',
                                    "%{$search}%"
                                );
                            }
                        );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Category
        |--------------------------------------------------------------------------
        */

        if ($this->categoryId) {

            $productsQuery->where(
                'category_id',
                $this->categoryId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Sort
        |--------------------------------------------------------------------------
        */

        match ($this->sort) {

            'name' =>
                $productsQuery
                    ->orderBy('name'),

            'oldest' =>
                $productsQuery
                    ->oldest(),

            default =>
                $productsQuery
                    ->latest(),
        };

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $products =
            $productsQuery
                ->paginate(
                    $this->perPage
                );

        /*
        |--------------------------------------------------------------------------
        | Branch prices for current page
        |--------------------------------------------------------------------------
        */

        $branchPrices = collect();

        if (
            $this->branchId &&
            $products->count()
        ) {

            $branchPrices =
                DB::table(
                    'branch_products'
                )
                    ->where(
                        'tenant_id',
                        $this->tenantId
                    )
                    ->where(
                        'branch_id',
                        $this->branchId
                    )
                    ->whereIn(
                        'product_id',
                        $products
                            ->pluck('id')
                            ->all()
                    )
                    ->get()
                    ->keyBy(
                        'product_id'
                    );
        }

        /*
        |--------------------------------------------------------------------------
        | Cart
        |--------------------------------------------------------------------------
        */

        $cart =
            $this->getCart();

        $cartCount = 0;

        $cartTotal = 0.00;

        foreach (
            $cart as $item
        ) {

            $quantity =
                (float) (
                    $item['quantity'] ?? 0
                );

            $price =
                (float) (
                    $item['price'] ?? 0
                );

            $cartCount +=
                $quantity;

            $cartTotal +=
                $price *
                $quantity;
        }

        $cartTotal =
            round(
                $cartTotal,
                2
            );

        /*
        |--------------------------------------------------------------------------
        | Render
        |--------------------------------------------------------------------------
        */

        return $this->view([
            'tenant' =>
                $tenant,

            'categories' =>
                $categories,

            'products' =>
                $products,

            'branchPrices' =>
                $branchPrices,

            'cart' =>
                $cart,

            'cartCount' =>
                $cartCount,

            'cartTotal' =>
                $cartTotal,
        ]);
    }
};
?>

<div
    x-data="{
        cartOpen: false,
        toast: '',

        showToast(message) {
            this.toast = message;

            setTimeout(() => {
                this.toast = '';
            }, 2500);
        }
    }"
    x-on:toast.window="showToast($event.detail.message)"
    class="min-h-screen bg-zinc-50 dark:bg-zinc-950"
    dir="rtl"
>
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    {{-- ============================================================
         TOAST
    ============================================================ --}}
    <div
        x-cloak
        x-show="toast"
        x-transition
        class="fixed bottom-6 left-1/2 z-[100] -translate-x-1/2"
    >
        <div class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-white px-5 py-3 text-sm font-medium text-zinc-800 shadow-2xl dark:border-emerald-900 dark:bg-zinc-900 dark:text-zinc-100">

            <div class="flex size-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                <flux:icon
                    name="check"
                    class="size-4"
                />
            </div>

            <span x-text="toast"></span>
        </div>
    </div>

    {{-- ============================================================
         HEADER
    ============================================================ --}}
    <header class="sticky top-0 z-30 border-b border-zinc-200/80 bg-white/95 backdrop-blur-xl dark:border-zinc-800 dark:bg-zinc-950/95">

        <div class="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8">

            <div class="flex min-h-20 items-center justify-between gap-4">

                {{-- Store --}}
                <div class="flex min-w-0 items-center gap-3">

                    <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900">

                        <span class="text-lg font-black">
                            {{ mb_substr($tenant->name ?? 'م', 0, 1) }}
                        </span>

                    </div>

                    <div class="min-w-0">

                        <h1 class="truncate text-base font-bold text-zinc-900 dark:text-white sm:text-lg">
                            {{ $tenant->name }}
                        </h1>

                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">

                            <span class="size-1.5 rounded-full bg-emerald-500"></span>

                            المتجر الإلكتروني

                        </div>

                    </div>

                </div>

                {{-- Phone --}}
                @if($tenant->phone)

                    <a
                        href="tel:{{ preg_replace('/\D+/', '', $tenant->phone) }}"
                        class="hidden items-center gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-600 transition hover:border-zinc-300 hover:bg-zinc-50 sm:flex dark:border-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-900"
                    >

                        <flux:icon
                            name="phone"
                            class="size-4"
                        />

                        <span dir="ltr">
                            {{ $tenant->phone }}
                        </span>

                    </a>

                @endif

                {{-- Mobile Cart --}}
                <button
                    type="button"
                    @click="cartOpen = true"
                    class="relative flex size-11 shrink-0 items-center justify-center rounded-2xl bg-zinc-900 text-white shadow-sm transition active:scale-95 lg:hidden dark:bg-white dark:text-zinc-900"
                >

                    <flux:icon
                        name="shopping-cart"
                        class="size-5"
                    />

                    @if($cartCount > 0)

                        <span class="absolute -right-1 -top-1 flex min-w-5 items-center justify-center rounded-full bg-emerald-500 px-1.5 py-0.5 text-[10px] font-bold text-white ring-2 ring-white dark:ring-zinc-950">
                            {{ $cartCount }}
                        </span>

                    @endif

                </button>

            </div>

        </div>

    </header>

    {{-- ============================================================
         MAIN
    ============================================================ --}}
    <main class="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_390px] lg:items-start">

            {{-- ========================================================
                 PRODUCTS
            ========================================================= --}}
            <section class="min-w-0">

                {{-- Hero --}}
                <div class="mb-6 rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-7">

                    <div class="max-w-2xl">

                        <div class="mb-3 inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">

                            <span class="size-1.5 rounded-full bg-emerald-500"></span>

                            اطلب الآن

                        </div>

                        <h2 class="text-2xl font-black tracking-tight text-zinc-950 dark:text-white sm:text-3xl">
                            اختر منتجاتك بسهولة
                        </h2>

                        <p class="mt-2 text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                            ابحث عن المنتج، اختر الكمية، وأرسل طلبك مباشرة إلى المتجر.
                        </p>

                    </div>

                </div>

                {{-- ====================================================
                     SEARCH / FILTERS
                ===================================================== --}}
                <div class="mb-5 rounded-3xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-4">

                    <div class="flex flex-col gap-3 xl:flex-row">

                        {{-- Search --}}
                        <div class="relative min-w-0 flex-1">

                            <div class="pointer-events-none absolute inset-y-0 right-4 flex items-center">

                                <flux:icon
                                    name="magnifying-glass"
                                    class="size-5 text-zinc-400"
                                />

                            </div>

                            <input
                                type="search"
                                wire:model.live.debounce.350ms="search"
                                placeholder="ابحث باسم المنتج أو الباركود..."
                                autocomplete="off"
                                class="h-12 w-full rounded-2xl border border-zinc-200 bg-zinc-50 pr-12 pl-12 text-sm text-zinc-900 outline-none transition focus:border-zinc-400 focus:bg-white focus:ring-4 focus:ring-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-zinc-500 dark:focus:bg-zinc-800 dark:focus:ring-zinc-800"
                            />

                            @if($search !== '')

                                <button
                                    type="button"
                                    wire:click="$set('search', '')"
                                    class="absolute inset-y-0 left-3 flex items-center px-2 text-zinc-400 hover:text-zinc-700 dark:hover:text-white"
                                >

                                    <flux:icon
                                        name="x-mark"
                                        class="size-4"
                                    />

                                </button>

                            @endif

                        </div>

                        {{-- Sort --}}
                        <div class="relative xl:w-48">

                            <select
                                wire:model.live="sort"
                                class="h-12 w-full appearance-none rounded-2xl border border-zinc-200 bg-zinc-50 px-4 text-sm font-medium text-zinc-700 outline-none focus:border-zinc-400 focus:ring-4 focus:ring-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:focus:ring-zinc-800"
                            >

                                <option value="latest">
                                    الأحدث أولًا
                                </option>

                                <option value="name">
                                    حسب الاسم
                                </option>

                                <option value="oldest">
                                    الأقدم أولًا
                                </option>

                            </select>

                            <div class="pointer-events-none absolute inset-y-0 left-4 flex items-center">

                                <flux:icon
                                    name="chevron-down"
                                    class="size-4 text-zinc-400"
                                />

                            </div>

                        </div>

                        {{-- Per Page --}}
                        <div class="relative xl:w-36">

                            <select
                                wire:model.live="perPage"
                                class="h-12 w-full appearance-none rounded-2xl border border-zinc-200 bg-zinc-50 px-4 text-sm font-medium text-zinc-700 outline-none focus:border-zinc-400 focus:ring-4 focus:ring-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:focus:ring-zinc-800"
                            >

                                <option value="12">
                                    12 منتج
                                </option>

                                <option value="24">
                                    24 منتج
                                </option>

                                <option value="36">
                                    36 منتج
                                </option>

                            </select>

                            <div class="pointer-events-none absolute inset-y-0 left-4 flex items-center">

                                <flux:icon
                                    name="chevron-down"
                                    class="size-4 text-zinc-400"
                                />

                            </div>

                        </div>

                    </div>

                    {{-- Categories --}}
                    @if($categories->count())

                        <div class="mt-4 flex gap-2 overflow-x-auto pb-1">

                            <button
                                type="button"
                                wire:click="$set('categoryId', null)"
                                class="shrink-0 rounded-xl px-4 py-2 text-xs font-semibold transition {{ !$categoryId ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
                            >
                                الكل
                            </button>

                            @foreach($categories as $category)

                                <button
                                    type="button"
                                    wire:key="category-{{ $category->id }}"
                                    wire:click="$set('categoryId', {{ $category->id }})"
                                    class="shrink-0 rounded-xl px-4 py-2 text-xs font-semibold transition {{ $categoryId === $category->id ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
                                >
                                    {{ $category->name }}
                                </button>

                            @endforeach

                        </div>

                    @endif

                </div>

                {{-- Results --}}
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">

                    <div>

                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                            المنتجات
                        </h3>

                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">

                            {{ $products->total() }}

                            منتج

                            @if($search)

                                — نتائج البحث عن
                                "{{ $search }}"

                            @endif

                        </p>

                    </div>

                    @if($search || $categoryId)

                        <button
                            type="button"
                            wire:click="clearFilters"
                            class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-xs font-semibold text-zinc-600 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        >

                            <flux:icon
                                name="arrow-path"
                                class="size-3.5"
                            />

                            مسح الفلاتر

                        </button>

                    @endif

                </div>

                {{-- Products --}}
                @if($products->count())

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">

                        @foreach($products as $product)

                            @php

                                $inCart =
                                    isset(
                                        $cart[$product->id]
                                    );

                                $cartQuantity =
                                    $inCart
                                        ? (float) $cart[$product->id]['quantity']
                                        : 0;

                                $branchProduct =
                                    $branchPrices->get(
                                        $product->id
                                    );

                                $displayPrice =
                                    $product->website_price !== null
                                        ? (float) $product->website_price
                                        : (
                                            $branchProduct
                                                ? (float) $branchProduct->retail_price
                                                : 0
                                        );

                                $stock =
                                    $branchProduct
                                        ? (float) $branchProduct->stock_quantity
                                        : 0;

                                $available =
                                    $branchProduct &&
                                    $stock > 0;

                                $productImage =
                                    $product->image;

                                if (!$productImage) {

                                    $productImageUrl =
                                        asset(
                                            'images/default-product.png'
                                        );

                                } elseif (
                                    str_starts_with(
                                        $productImage,
                                        'http://'
                                    ) ||
                                    str_starts_with(
                                        $productImage,
                                        'https://'
                                    )
                                ) {

                                    $productImageUrl =
                                        $productImage;

                                } else {

                                    $productImageUrl =
                                        Storage::url(
                                            $productImage
                                        );
                                }

                            @endphp

                            <article
                                wire:key="product-{{ $product->id }}"
                                class="group overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg dark:border-zinc-800 dark:bg-zinc-900"
                            >

                                {{-- Image --}}
                                <div class="relative aspect-[4/3] overflow-hidden bg-zinc-100 dark:bg-zinc-800">

                                    <img
                                        src="{{ $productImageUrl }}"
                                        alt="{{ $product->name }}"
                                        loading="lazy"
                                        class="size-full object-cover transition duration-500 group-hover:scale-105"
                                    />

                                    {{-- Category --}}
                                    @if($product->category)

                                        <div class="absolute right-3 top-3">

                                            <span class="rounded-lg bg-white/90 px-2.5 py-1 text-[10px] font-bold text-zinc-700 shadow-sm backdrop-blur dark:bg-zinc-900/90 dark:text-zinc-200">
                                                {{ $product->category->name }}
                                            </span>

                                        </div>

                                    @endif

                                    {{-- Stock badge --}}
                                    @if(!$available)

                                        <div class="absolute inset-0 flex items-center justify-center bg-black/35">

                                            <span class="rounded-xl bg-white px-3 py-2 text-xs font-black text-zinc-800 shadow-lg">
                                                غير متوفر
                                            </span>

                                        </div>

                                    @elseif($inCart)

                                        <div class="absolute left-3 top-3">

                                            <span class="flex min-w-7 items-center justify-center rounded-full bg-emerald-500 px-2 py-1 text-[10px] font-black text-white shadow-sm">
                                                {{ $cartQuantity }}
                                            </span>

                                        </div>

                                    @endif

                                </div>

                                {{-- Content --}}
                                <div class="p-3.5">

                                    <h4 class="min-h-[42px] text-sm font-bold leading-5 text-zinc-900 dark:text-white">
                                        {{ $product->name }}
                                    </h4>

                                    <div class="mt-3 flex items-end justify-between gap-2">

                                        <div>

                                            <span class="text-base font-black text-zinc-950 dark:text-white">
                                                {{ number_format($displayPrice, 2) }}
                                            </span>

                                            <span class="mr-1 text-[10px] text-zinc-400">
                                                ₪
                                            </span>

                                        </div>

                                        @if($available)

                                            @if($inCart)

                                                <div class="flex items-center rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-800">

                                                    <button
                                                        type="button"
                                                        wire:click="updateQuantity({{ $product->id }}, {{ max(0, $cartQuantity - 1) }})"
                                                        class="flex size-7 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-white hover:text-zinc-900 dark:hover:bg-zinc-700 dark:hover:text-white"
                                                    >

                                                        <flux:icon
                                                            name="minus"
                                                            class="size-3.5"
                                                        />

                                                    </button>

                                                    <span class="w-7 text-center text-xs font-bold text-zinc-900 dark:text-white">
                                                        {{ $cartQuantity }}
                                                    </span>

                                                    <button
                                                        type="button"
                                                        wire:click="updateQuantity({{ $product->id }}, {{ min(99, $cartQuantity + 1) }})"
                                                        class="flex size-7 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-white hover:text-zinc-900 dark:hover:bg-zinc-700 dark:hover:text-white"
                                                    >

                                                        <flux:icon
                                                            name="plus"
                                                            class="size-3.5"
                                                        />

                                                    </button>

                                                </div>

                                            @else

                                                <button
                                                    type="button"
                                                    wire:click="addToCart({{ $product->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="addToCart({{ $product->id }})"
                                                    class="flex size-9 items-center justify-center rounded-xl bg-zinc-900 text-white transition hover:bg-zinc-700 active:scale-95 disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                                                >

                                                    <flux:icon
                                                        name="plus"
                                                        class="size-4"
                                                        wire:loading.remove
                                                        wire:target="addToCart({{ $product->id }})"
                                                    />

                                                    <flux:icon
                                                        name="arrow-path"
                                                        class="hidden size-4 animate-spin"
                                                        wire:loading.class="!block"
                                                        wire:target="addToCart({{ $product->id }})"
                                                    />

                                                </button>

                                            @endif

                                        @else

                                            <span class="rounded-xl bg-zinc-100 px-3 py-2 text-[10px] font-bold text-zinc-400 dark:bg-zinc-800">
                                                غير متوفر
                                            </span>

                                        @endif

                                    </div>

                                </div>

                            </article>

                        @endforeach

                    </div>

                    {{-- Pagination --}}
                    <div class="mt-6">
                        {{ $products->links() }}
                    </div>

                @else

                    {{-- Empty Products --}}
                    <div class="rounded-3xl border border-dashed border-zinc-300 bg-white px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900">

                        <div class="mx-auto flex size-16 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800">

                            <flux:icon
                                name="magnifying-glass"
                                class="size-7"
                            />

                        </div>

                        <h3 class="mt-5 text-base font-bold text-zinc-900 dark:text-white">
                            لم نجد منتجات
                        </h3>

                        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                            جرّب تغيير كلمة البحث أو اختيار تصنيف آخر.
                        </p>

                        @if($search || $categoryId)

                            <button
                                type="button"
                                wire:click="clearFilters"
                                class="mt-5 rounded-xl bg-zinc-900 px-4 py-2.5 text-xs font-bold text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900"
                            >
                                عرض جميع المنتجات
                            </button>

                        @endif

                    </div>

                @endif

            </section>

            {{-- ========================================================
                 CART
            ========================================================= --}}
            <aside
                class="fixed inset-y-0 right-0 z-50 flex w-[min(92vw,420px)] flex-col border-l border-zinc-200 bg-white shadow-2xl transition-transform duration-300 dark:border-zinc-800 dark:bg-zinc-950 lg:sticky lg:top-24 lg:z-auto lg:h-[calc(100vh-7rem)] lg:w-auto lg:translate-x-0 lg:rounded-3xl lg:border lg:shadow-sm"
                :class="cartOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
            >

                {{-- Mobile Overlay --}}
                <div
                    x-cloak
                    x-show="cartOpen"
                    x-transition.opacity
                    @click="cartOpen = false"
                    class="fixed inset-0 -z-10 bg-black/40 lg:hidden"
                ></div>

                {{-- Cart Header --}}
                <div class="flex shrink-0 items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">

                    <div>

                        <h2 class="text-base font-black text-zinc-900 dark:text-white">
                            سلة الطلب
                        </h2>

                        <p class="mt-0.5 text-xs text-zinc-500">
                            {{ $cartCount }} قطعة
                        </p>

                    </div>

                    <button
                        type="button"
                        @click="cartOpen = false"
                        class="flex size-9 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 hover:bg-zinc-200 lg:hidden dark:bg-zinc-800 dark:hover:bg-zinc-700"
                    >

                        <flux:icon
                            name="x-mark"
                            class="size-4"
                        />

                    </button>

                </div>

                {{-- Cart Content --}}
                <div class="min-h-0 flex-1 overflow-y-auto">

                    @if(count($cart))

                        <div class="space-y-3 p-4">

                            @foreach($cart as $item)

                                <div
                                    wire:key="cart-item-{{ $item['id'] }}"
                                    class="rounded-2xl border border-zinc-200 p-3 dark:border-zinc-800"
                                >

                                    <div class="flex gap-3">

                                        {{-- Image --}}
                                        <div class="size-16 shrink-0 overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">

                                            <img
                                                src="{{ $item['image'] }}"
                                                alt="{{ $item['name'] }}"
                                                class="size-full object-cover"
                                            />

                                        </div>

                                        {{-- Info --}}
                                        <div class="min-w-0 flex-1">

                                            <div class="flex items-start justify-between gap-2">

                                                <h3 class="line-clamp-2 text-xs font-bold leading-5 text-zinc-900 dark:text-white">
                                                    {{ $item['name'] }}
                                                </h3>

                                                <button
                                                    type="button"
                                                    wire:click="removeItem({{ $item['id'] }})"
                                                    class="shrink-0 text-zinc-400 transition hover:text-red-500"
                                                >

                                                    <flux:icon
                                                        name="trash"
                                                        class="size-4"
                                                    />

                                                </button>

                                            </div>

                                            <div class="mt-2 flex items-center justify-between">

                                                {{-- Quantity --}}
                                                <div class="flex items-center rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-800">

                                                    <button
                                                        type="button"
                                                        wire:click="updateQuantity({{ $item['id'] }}, {{ max(0, (float)$item['quantity'] - 1) }})"
                                                        class="flex size-7 items-center justify-center rounded-lg text-zinc-500 hover:bg-white dark:hover:bg-zinc-700"
                                                    >

                                                        <flux:icon
                                                            name="minus"
                                                            class="size-3"
                                                        />

                                                    </button>

                                                    <span class="w-7 text-center text-xs font-bold text-zinc-900 dark:text-white">
                                                        {{ $item['quantity'] }}
                                                    </span>

                                                    <button
                                                        type="button"
                                                        wire:click="updateQuantity({{ $item['id'] }}, {{ min(99, (float)$item['quantity'] + 1) }})"
                                                        class="flex size-7 items-center justify-center rounded-lg text-zinc-500 hover:bg-white dark:hover:bg-zinc-700"
                                                    >

                                                        <flux:icon
                                                            name="plus"
                                                            class="size-3"
                                                        />

                                                    </button>

                                                </div>

                                                {{-- Price --}}
                                                <span class="text-sm font-black text-zinc-900 dark:text-white">

                                                    {{ number_format(
                                                        (float)$item['price'] *
                                                        (float)$item['quantity'],
                                                        2
                                                    ) }}

                                                    <span class="text-[10px] text-zinc-400">
                                                        ₪
                                                    </span>

                                                </span>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            @endforeach

                        </div>

                        {{-- =================================================
                             CHECKOUT
                        ================================================== --}}
                        <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">

                            <form
                                wire:submit="placeOrder"
                                class="space-y-4"
                            >

                                <div>

                                    <h3 class="text-sm font-black text-zinc-900 dark:text-white">
                                        بيانات التوصيل
                                    </h3>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        أدخل بياناتك لإرسال الطلب إلى المتجر.
                                    </p>

                                </div>

                                {{-- Name --}}
                                <div>

                                    <label class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                        الاسم الكامل
                                    </label>

                                    <input
                                        type="text"
                                        wire:model="customer_name"
                                        placeholder="مثال: محمد أحمد"
                                        autocomplete="name"
                                        class="h-11 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-sm outline-none focus:border-zinc-400 focus:ring-4 focus:ring-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:ring-zinc-800"
                                    />

                                    @error('customer_name')

                                        <p class="mt-1 text-[11px] text-red-500">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                </div>

                                {{-- Phone --}}
                                <div>

                                    <label class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                        رقم الهاتف
                                    </label>

                                    <input
                                        type="tel"
                                        wire:model="customer_phone"
                                        dir="ltr"
                                        placeholder="05xxxxxxxx"
                                        autocomplete="tel"
                                        class="h-11 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 text-left text-sm outline-none focus:border-zinc-400 focus:ring-4 focus:ring-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:ring-zinc-800"
                                    />

                                    @error('customer_phone')

                                        <p class="mt-1 text-[11px] text-red-500">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                </div>

                                {{-- Address --}}
                                <div>

                                    <label class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                                        عنوان التوصيل
                                    </label>

                                    <textarea
                                        wire:model="customer_address"
                                        rows="3"
                                        placeholder="المدينة، المنطقة، الشارع..."
                                        autocomplete="street-address"
                                        class="w-full resize-none rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-3 text-sm outline-none focus:border-zinc-400 focus:ring-4 focus:ring-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:ring-zinc-800"
                                    ></textarea>

                                    @error('customer_address')

                                        <p class="mt-1 text-[11px] text-red-500">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                </div>

                                {{-- Summary --}}
                                <div class="rounded-2xl bg-zinc-50 p-4 dark:bg-zinc-800/70">

                                    <div class="flex items-center justify-between text-xs text-zinc-500">

                                        <span>
                                            عدد القطع
                                        </span>

                                        <span class="font-bold text-zinc-800 dark:text-zinc-200">
                                            {{ $cartCount }}
                                        </span>

                                    </div>

                                    <div class="mt-2 flex items-center justify-between text-xs text-zinc-500">

                                        <span>
                                            الإجمالي
                                        </span>

                                        <span class="text-lg font-black text-zinc-950 dark:text-white">

                                            {{ number_format(
                                                $cartTotal,
                                                2
                                            ) }}

                                            <span class="text-xs text-zinc-400">
                                                ₪
                                            </span>

                                        </span>

                                    </div>

                                </div>

                                {{-- Submit --}}
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="placeOrder"
                                    class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 text-sm font-bold text-white transition hover:bg-zinc-700 active:scale-[.99] disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                                >

                                    <flux:icon
                                        name="paper-airplane"
                                        class="size-4"
                                        wire:loading.remove
                                        wire:target="placeOrder"
                                    />

                                    <flux:icon
                                        name="arrow-path"
                                        class="hidden size-4 animate-spin"
                                        wire:loading.class="!block"
                                        wire:target="placeOrder"
                                    />

                                    <span
                                        wire:loading.remove
                                        wire:target="placeOrder"
                                    >
                                        إرسال الطلب عبر واتساب
                                    </span>

                                    <span
                                        wire:loading
                                        wire:target="placeOrder"
                                    >
                                        جاري إنشاء الطلب...
                                    </span>

                                </button>

                                <p class="text-center text-[10px] leading-5 text-zinc-400">
                                    بعد إنشاء الطلب سيتم فتح واتساب لإرسال تفاصيل الطلب إلى المتجر.
                                </p>

                            </form>

                        </div>

                    @else

                        {{-- Empty Cart --}}
                        <div class="flex h-full min-h-[420px] flex-col items-center justify-center px-6 text-center">

                            <div class="flex size-20 items-center justify-center rounded-3xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800">

                                <flux:icon
                                    name="shopping-cart"
                                    class="size-8"
                                />

                            </div>

                            <h3 class="mt-5 text-sm font-black text-zinc-900 dark:text-white">
                                السلة فارغة
                            </h3>

                            <p class="mt-2 max-w-xs text-xs leading-6 text-zinc-500">
                                أضف المنتجات التي تريدها من القائمة وسيظهر طلبك هنا.
                            </p>

                            <button
                                type="button"
                                @click="cartOpen = false"
                                class="mt-5 rounded-xl bg-zinc-900 px-4 py-2.5 text-xs font-bold text-white lg:hidden dark:bg-white dark:text-zinc-900"
                            >
                                تصفح المنتجات
                            </button>

                        </div>

                    @endif

                </div>

            </aside>

        </div>

    </main>

    {{-- ============================================================
         MOBILE CART BUTTON
    ============================================================ --}}
    @if($cartCount > 0)

        <button
            type="button"
            @click="cartOpen = true"
            class="fixed bottom-5 left-5 right-5 z-40 flex h-14 items-center justify-between rounded-2xl bg-zinc-900 px-5 text-white shadow-2xl lg:hidden dark:bg-white dark:text-zinc-900"
        >

            <span class="flex items-center gap-2 text-sm font-bold">

                <flux:icon
                    name="shopping-cart"
                    class="size-5"
                />

                عرض السلة

                <span class="rounded-lg bg-white/15 px-2 py-1 text-xs dark:bg-zinc-900/10">
                    {{ $cartCount }}
                </span>

            </span>

            <span class="text-sm font-black">

                {{ number_format(
                    $cartTotal,
                    2
                ) }}

                ₪

            </span>

        </button>

    @endif

</div>
