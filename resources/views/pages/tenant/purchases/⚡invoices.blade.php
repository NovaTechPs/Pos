<?php

use Livewire\Component;

use App\Models\Product;
use App\Models\Party;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\BranchProduct;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    /*
    |--------------------------------------------------------------------------
    | Invoice
    |--------------------------------------------------------------------------
    */

    public string $invoice_number = '';
    public string $date = '';

    /*
     * ID من جدول parties.
     *
     * أبقينا اسم customer_id هنا لأننا سنستخدمه
     * مع أعمدة customer_id الحالية في orders/payment_transactions.
     */
    public ?int $customer_id = null;

    public string $currency = 'USD';
    public float $exchange_rate = 1.0;

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */

    public bool $is_cash = true;

    /*
    |--------------------------------------------------------------------------
    | Product row
    |--------------------------------------------------------------------------
    */

    public ?int $selected_product_id = null;

    public string $item_barcode = '';

    public string $warehouse = 'الرئيسي';

    public string $unit = 'حبة';

    public float $item_quantity = 1.0;

    public float $item_price = 0.0;

    public float $item_discount = 0.0;

    /*
    |--------------------------------------------------------------------------
    | Cart
    |--------------------------------------------------------------------------
    */

    public array $cart = [];

    /*
    |--------------------------------------------------------------------------
    | Invoice totals
    |--------------------------------------------------------------------------
    */

    public float $invoice_discount_val = 0.0;

    public float $invoice_discount_percent = 0.0;

    public float $tax_percent = 16.0;

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    public ?string $errorMessage = null;

    public ?string $successMessage = null;


    /*
    |--------------------------------------------------------------------------
    | Mount
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');

        $this->invoice_number = 'INV-' . time();
    }


    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    private function getTenantId(): ?int
    {
        $tenantId = session('active_tenant_id');

        if ($tenantId) {
            return (int) $tenantId;
        }

        $user = Auth::user();

        if ($user?->tenant_id) {
            return (int) $user->tenant_id;
        }

        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | Branch
    |--------------------------------------------------------------------------
    */

    private function getBranchId(): ?int
    {
        $branchId = session('active_branch_id');

        if ($branchId) {
            return (int) $branchId;
        }

        $user = Auth::user();

        if ($user?->branch_id) {
            return (int) $user->branch_id;
        }

        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | Customers / Parties
    |--------------------------------------------------------------------------
    */

    public function getCustomersProperty()
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            return collect();
        }

        return Party::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_active', true)
            ->select([
                'id',
                'name',
                'phone',
                'current_balance',
            ])
            ->orderBy('name')
            ->get();
    }


    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    |
    | products:
    |   id
    |   name
    |   cost_price
    |
    | product_barcodes:
    |   barcode
    |
    | branch_products:
    |   quantity
    |   retail_price
    |   wholesale_price
    |
    |--------------------------------------------------------------------------
    */

    public function getProductsProperty()
    {
        $tenantId = $this->getTenantId();

        $branchId = $this->getBranchId();

        if (!$tenantId || !$branchId) {
            return collect();
        }

        return Product::query()
            ->where('tenant_id', $tenantId)
            ->select([
                'id',
                'name',
                'cost_price',
            ])
            ->with([
                'barcodes',
                'branchProducts' => function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId);
                },
            ])
            ->orderBy('name')
            ->get();
    }


    /*
    |--------------------------------------------------------------------------
    | Scan barcode
    |--------------------------------------------------------------------------
    */

    public function scanBarcode(): void
    {
        $this->errorMessage = null;

        $barcode = trim($this->item_barcode);

        if ($barcode === '') {
            return;
        }

        $tenantId = $this->getTenantId();

        $branchId = $this->getBranchId();

        if (!$tenantId) {
            $this->errorMessage =
                'لم يتم تحديد المتجر الحالي.';

            return;
        }

        if (!$branchId) {
            $this->errorMessage =
                'لم يتم تحديد الفرع الحالي.';

            return;
        }

        /*
         * البحث في product_barcodes وليس products.
         */
        $product = Product::query()
            ->where('tenant_id', $tenantId)

            ->whereHas('barcodes', function ($query) use (
                $tenantId,
                $barcode
            ) {
                $query
                    ->where('tenant_id', $tenantId)
                    ->where('barcode', $barcode);
            })

            ->with([
                'barcodes',

                'branchProducts' => function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId);
                },
            ])

            ->first();

        if (!$product) {
            $this->errorMessage =
                'لم يتم العثور على المنتج برقم الباركود: '
                . $barcode;

            return;
        }

        /*
         * بيانات المنتج في الفرع.
         */
        $branchProduct = $product->branchProducts->first();

        if (!$branchProduct) {
            $this->errorMessage =
                'المنتج غير مرتبط بالفرع الحالي.';

            return;
        }

        /*
         * سعر البيع من branch_products.
         */
        $price = (float) $branchProduct->retail_price;

        /*
         * المخزون من branch_products.quantity.
         */
        $stock = (float) $branchProduct->quantity;

        if ($stock <= 0) {
            $this->errorMessage =
                'المنتج غير متوفر في المخزون.';

            return;
        }

        $this->pushToCart(
            $product,
            1.0,
            $price
        );

        $this->item_barcode = '';

        $this->dispatch('focus-barcode');
    }


    /*
    |--------------------------------------------------------------------------
    | Product selected
    |--------------------------------------------------------------------------
    */

    public function updatedSelectedProductId($productId): void
    {
        if (!$productId) {
            return;
        }

        $tenantId = $this->getTenantId();

        $branchId = $this->getBranchId();

        if (!$tenantId || !$branchId) {
            return;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)

            ->with([
                'barcodes',

                'branchProducts' => function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId);
                },
            ])

            ->find($productId);

        if (!$product) {
            return;
        }

        /*
         * الباركود من product_barcodes.
         */
        $this->item_barcode =
            $product->barcodes->first()?->barcode ?? '';

        /*
         * بيانات الفرع.
         */
        $branchProduct =
            $product->branchProducts->first();

        if (!$branchProduct) {
            $this->item_price = 0.0;

            $this->errorMessage =
                'المنتج غير مرتبط بالفرع الحالي.';

            return;
        }

        /*
         * سعر البيع من branch_products.
         */
        $this->item_price =
            (float) $branchProduct->retail_price;

        $this->errorMessage = null;

        $this->dispatch('focus-qty');
    }


    /*
    |--------------------------------------------------------------------------
    | Add item
    |--------------------------------------------------------------------------
    */

    public function addItemRow(): void
    {
        $this->errorMessage = null;

        if (!$this->selected_product_id) {
            $this->errorMessage =
                'يرجى اختيار صنف أولاً.';

            return;
        }

        if ($this->item_quantity <= 0) {
            $this->errorMessage =
                'الكمية يجب أن تكون أكبر من صفر.';

            return;
        }

        if ($this->item_price < 0) {
            $this->errorMessage =
                'سعر البيع غير صحيح.';

            return;
        }

        $tenantId = $this->getTenantId();

        $branchId = $this->getBranchId();

        if (!$tenantId) {
            $this->errorMessage =
                'لم يتم تحديد المتجر الحالي.';

            return;
        }

        if (!$branchId) {
            $this->errorMessage =
                'لم يتم تحديد الفرع الحالي.';

            return;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)

            ->with([
                'barcodes',

                'branchProducts' => function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId);
                },
            ])

            ->find($this->selected_product_id);

        if (!$product) {
            $this->errorMessage =
                'المنتج غير موجود.';

            return;
        }

        $branchProduct =
            $product->branchProducts->first();

        if (!$branchProduct) {
            $this->errorMessage =
                'المنتج غير مرتبط بالفرع الحالي.';

            return;
        }

        /*
         * التأكد من المخزون.
         */
        $existingQuantity = 0;

        foreach ($this->cart as $item) {
            if (
                (int) $item['product_id']
                === (int) $product->id
                &&
                $item['unit'] === $this->unit
            ) {
                $existingQuantity +=
                    (float) $item['quantity'];
            }
        }

        $requestedQuantity =
            $existingQuantity
            + $this->item_quantity;

        $availableQuantity =
            (float) $branchProduct->quantity;

        if ($requestedQuantity > $availableQuantity) {
            $this->errorMessage =
                'الكمية المطلوبة أكبر من الكمية المتوفرة. '
                . 'المتوفر: '
                . $availableQuantity;

            return;
        }

        $this->pushToCart(
            $product,
            $this->item_quantity,
            $this->item_price,
            $this->item_discount
        );

        $this->resetInputRow();

        $this->dispatch('focus-barcode');
    }


    /*
    |--------------------------------------------------------------------------
    | Push product to cart
    |--------------------------------------------------------------------------
    */

    private function pushToCart(
        Product $product,
        float $qty,
        float $price,
        float $discount = 0.0
    ): void {
        /*
         * الباركود من product_barcodes.
         */
        $barcode =
            $product->barcodes->first()?->barcode;

        /*
         * إذا كان المنتج موجودًا مسبقًا في السلة،
         * نزيد الكمية.
         */
        foreach ($this->cart as $index => $item) {

            if (
                (int) $item['product_id']
                === (int) $product->id
                &&
                $item['unit'] === $this->unit
            ) {

                $this->cart[$index]['quantity'] += $qty;

                $this->cart[$index]['subtotal'] =
                    max(
                        0,
                        (
                            $this->cart[$index]['price']
                            *
                            $this->cart[$index]['quantity']
                        )
                        -
                        $this->cart[$index]['discount']
                    );

                return;
            }
        }

        $rowSubtotal =
            ($price * $qty)
            - $discount;

        $this->cart[] = [
            'product_id' => $product->id,

            'name' => $product->name,

            'barcode' => $barcode,

            'warehouse' => $this->warehouse,

            'unit' => $this->unit,

            'quantity' => $qty,

            'price' => $price,

            'discount' => $discount,

            'subtotal' => max(
                0,
                $rowSubtotal
            ),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Reset product row
    |--------------------------------------------------------------------------
    */

    private function resetInputRow(): void
    {
        $this->selected_product_id = null;

        $this->item_barcode = '';

        $this->item_quantity = 1.0;

        $this->item_price = 0.0;

        $this->item_discount = 0.0;
    }


    /*
    |--------------------------------------------------------------------------
    | Remove item
    |--------------------------------------------------------------------------
    */

    public function removeItemRow($index): void
    {
        if (!isset($this->cart[$index])) {
            return;
        }

        unset($this->cart[$index]);

        $this->cart = array_values(
            $this->cart
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Update quantity
    |--------------------------------------------------------------------------
    */

    public function updateCartQty(
        $index,
        $qty
    ): void {
        if (!isset($this->cart[$index])) {
            return;
        }

        $qty = (float) $qty;

        if ($qty <= 0) {
            return;
        }

        $tenantId = $this->getTenantId();

        $branchId = $this->getBranchId();

        if (!$tenantId || !$branchId) {
            return;
        }

        /*
         * التأكد من المخزون قبل تعديل الكمية.
         */
        $branchProduct = BranchProduct::query()
            ->where('branch_id', $branchId)
            ->where(
                'product_id',
                $this->cart[$index]['product_id']
            )
            ->first();

        if (!$branchProduct) {
            $this->errorMessage =
                'المنتج غير موجود في مخزون الفرع.';

            return;
        }

        if (
            $qty > (float) $branchProduct->quantity
        ) {
            $this->errorMessage =
                'الكمية المطلوبة أكبر من المخزون. '
                . 'المتوفر: '
                . $branchProduct->quantity;

            return;
        }

        $this->cart[$index]['quantity'] = $qty;

        $this->cart[$index]['subtotal'] =
            max(
                0,
                (
                    $this->cart[$index]['price']
                    *
                    $this->cart[$index]['quantity']
                )
                -
                $this->cart[$index]['discount']
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Subtotal
    |--------------------------------------------------------------------------
    */

    public function getSubtotalBeforeDiscountProperty(): float
    {
        return (float) array_sum(
            array_column(
                $this->cart,
                'subtotal'
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Invoice discount
    |--------------------------------------------------------------------------
    */

    public function getFinalDiscountProperty(): float
    {
        if ($this->invoice_discount_percent > 0) {

            return (
                $this->subtotalBeforeDiscount
                *
                $this->invoice_discount_percent
            ) / 100;
        }

        return max(
            0,
            $this->invoice_discount_val
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    */

    public function getTaxValueProperty(): float
    {
        $afterDiscount = max(
            0,
            $this->subtotalBeforeDiscount
            -
            $this->finalDiscount
        );

        return (
            $afterDiscount
            *
            $this->tax_percent
        ) / 100;
    }


    /*
    |--------------------------------------------------------------------------
    | Total
    |--------------------------------------------------------------------------
    */

    public function getTotalProperty(): float
    {
        return max(
            0,
            (
                $this->subtotalBeforeDiscount
                -
                $this->finalDiscount
            )
            +
            $this->taxValue
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Save invoice
    |--------------------------------------------------------------------------
    */

    public function saveInvoice(): void
    {
        $this->errorMessage = null;

        $this->successMessage = null;

        /*
         * يجب أن تحتوي الفاتورة على أصناف.
         */
        if (empty($this->cart)) {

            $this->errorMessage =
                'لا يمكن حفظ فاتورة فارغة!';

            return;
        }

        /*
         * يجب اختيار عميل.
         */
        if (!$this->customer_id) {

            $this->errorMessage =
                'يرجى تحديد العميل أولاً!';

            return;
        }

        $user = Auth::user();

        if (!$user) {

            $this->errorMessage =
                'يجب تسجيل الدخول أولاً.';

            return;
        }

        $tenantId = $this->getTenantId();

        $branchId = $this->getBranchId();

        if (!$tenantId) {

            $this->errorMessage =
                'لم يتم تحديد المتجر الحالي.';

            return;
        }

        if (!$branchId) {

            $this->errorMessage =
                'لم يتم تحديد الفرع الحالي.';

            return;
        }

        /*
         * التحقق من العميل.
         */
        $party = Party::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $this->customer_id)
            ->whereIn(
                'type',
                ['customer', 'both']
            )
            ->where('is_active', true)
            ->first();

        if (!$party) {

            $this->errorMessage =
                'العميل المحدد غير صالح.';

            return;
        }

        try {

            DB::transaction(function () use (
                $user,
                $tenantId,
                $branchId,
                $party
            ) {

                $totalAmount =
                    (float) $this->total;

                $paidAmount =
                    $this->is_cash
                        ? $totalAmount
                        : 0.0;


                /*
                |--------------------------------------------------------------------------
                | Create Order
                |--------------------------------------------------------------------------
                */

                $order = Order::create([

                    'tenant_id' => $tenantId,

                    'branch_id' => $branchId,

                    'user_id' => $user->id,

                    /*
                     * ID من parties.
                     */
                    'customer_id' => $party->id,

                    'invoice_number' =>
                        $this->invoice_number,

                    'type' => 'retail',

                    'subtotal' =>
                        $this->subtotalBeforeDiscount,

                    'discount' =>
                        $this->finalDiscount,

                    /*
                     * إذا كان migration orders عندك
                     * يستخدم tax_amount بدل tax،
                     * غيّر هذا السطر إلى tax_amount.
                     */
                    'tax' =>
                        $this->taxValue,

                    'total' =>
                        $totalAmount,

                    'paid_amount' =>
                        $paidAmount,

                    'payment_status' =>
                        $this->is_cash
                            ? 'paid'
                            : 'unpaid',

                    'currency' =>
                        $this->currency,

                    'exchange_rate' =>
                        $this->exchange_rate,
                ]);


                /*
                |--------------------------------------------------------------------------
                | Order Items + Stock
                |--------------------------------------------------------------------------
                */

                foreach ($this->cart as $item) {

                    /*
                     * قفل سجل المخزون لمنع
                     * عمليات البيع المتزامنة.
                     */
                    $branchProduct =
                        BranchProduct::query()
                            ->where(
                                'branch_id',
                                $branchId
                            )
                            ->where(
                                'product_id',
                                $item['product_id']
                            )
                            ->lockForUpdate()
                            ->first();

                    if (!$branchProduct) {

                        throw new \RuntimeException(
                            'المنتج '
                            . $item['name']
                            . ' غير موجود في مخزون الفرع.'
                        );
                    }

                    /*
                     * التحقق من المخزون.
                     */
                    if (
                        (float) $branchProduct->quantity
                        <
                        (float) $item['quantity']
                    ) {

                        throw new \RuntimeException(
                            'الكمية المتوفرة من المنتج '
                            . $item['name']
                            . ' غير كافية. '
                            . 'المتوفر: '
                            . $branchProduct->quantity
                        );
                    }


                    /*
                     * إنشاء OrderItem.
                     */
                    OrderItem::create([

                        'tenant_id' =>
                            $tenantId,

                        'order_id' =>
                            $order->id,

                        'product_id' =>
                            $item['product_id'],

                        'quantity' =>
                            $item['quantity'],

                        'unit_price' =>
                            $item['price'],

                        'discount' =>
                            $item['discount'],

                        'total_price' =>
                            $item['subtotal'],
                    ]);


                    /*
                     * خصم المخزون.
                     *
                     * حسب بنية branch_products
                     * عندك العمود هو quantity.
                     */
                    $branchProduct->decrement(
                        'quantity',
                        $item['quantity']
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Cash Payment
                |--------------------------------------------------------------------------
                */

                if ($this->is_cash) {

                    PaymentTransaction::create([

                        'tenant_id' =>
                            $tenantId,

                        'branch_id' =>
                            $branchId,

                        /*
                         * ID من parties.
                         *
                         * نستخدم customer_id طالما
                         * جدول payment_transactions
                         * عندك لم يتغير بعد.
                         */
                        'customer_id' =>
                            $party->id,

                        'type' =>
                            'receipt',

                        'amount' =>
                            $totalAmount,

                        'reference_type' =>
                            'order',

                        'reference_id' =>
                            $order->id,

                        'description' =>
                            'سند قبض آلي ناتج عن '
                            . 'الفاتورة رقم: '
                            . $order->invoice_number,

                        'payment_date' =>
                            $this->date,
                    ]);

                } else {

                    /*
                     |--------------------------------------------------------------------------
                     | Credit Sale
                     |--------------------------------------------------------------------------
                     |
                     | parties.current_balance
                     |
                     */

                    $party->increment(
                        'current_balance',
                        $totalAmount
                    );
                }
            });


            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            $this->successMessage =
                'تم حفظ الفاتورة بنجاح!';


            /*
             * إعادة الفاتورة لحالة جديدة.
             */
            $this->cart = [];

            $this->customer_id = null;

            $this->invoice_discount_val = 0.0;

            $this->invoice_discount_percent = 0.0;

            $this->invoice_number =
                'INV-' . time();

            $this->date =
                now()->format('Y-m-d');

            $this->resetInputRow();

            $this->dispatch('focus-barcode');

        } catch (\Throwable $e) {

            report($e);

            $this->errorMessage =
                'حدث خطأ أثناء حفظ الفاتورة: '
                . $e->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        return $this->view()
            ->layout('layouts::tenant');
    }
};
?>
<flux:main class="p-4 md:p-6">
<div
    x-data="{
        init() {

            window.addEventListener('focus-barcode', () => {

                this.$nextTick(() => {

                    document
                        .getElementById('item_barcode')
                        ?.focus();

                });

            });


            window.addEventListener('focus-qty', () => {

                this.$nextTick(() => {

                    document
                        .getElementById('item_quantity')
                        ?.focus();

                });

            });


            document.addEventListener('keydown', (event) => {

                /*
                 * F2 = Barcode
                 */
                if (event.key === 'F2') {

                    event.preventDefault();

                    this.$nextTick(() => {

                        document
                            .getElementById('item_barcode')
                            ?.focus();

                    });

                }


                /*
                 * F3 = Product
                 */
                if (event.key === 'F3') {

                    event.preventDefault();

                    this.$nextTick(() => {

                        document
                            .getElementById('selected_product_id')
                            ?.focus();

                    });

                }

            });

        }
    }"
>



        {{-- ========================================================= --}}
        {{-- Header --}}
        {{-- ========================================================= --}}

        <div class="mb-6 flex items-center justify-between">

            <div>

                <flux:heading size="xl">
                    فاتورة مبيعات
                </flux:heading>

                <flux:text class="mt-1 text-zinc-500">
                    إنشاء فاتورة مبيعات جديدة
                </flux:text>

            </div>


            <div class="text-left">

                <div class="text-sm text-zinc-500">
                    رقم الفاتورة
                </div>

                <div class="font-semibold">
                    {{ $invoice_number }}
                </div>

            </div>

        </div>


        {{-- ========================================================= --}}
        {{-- Error --}}
        {{-- ========================================================= --}}

        @if($errorMessage)

            <div
                class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700"
            >
                {{ $errorMessage }}
            </div>

        @endif


        {{-- ========================================================= --}}
        {{-- Success --}}
        {{-- ========================================================= --}}

        @if($successMessage)

            <div
                class="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-green-700"
            >
                {{ $successMessage }}
            </div>

        @endif


        {{-- ========================================================= --}}
        {{-- Invoice Information --}}
        {{-- ========================================================= --}}

        <div
            class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3"
        >

            {{-- Date --}}

            <div>

                <flux:field>

                    <flux:label>
                        التاريخ
                    </flux:label>

                    <flux:input
                        type="date"
                        wire:model="date"
                    />

                </flux:field>

            </div>


            {{-- Customer --}}

            <div>

                <flux:field>

                    <flux:label>
                        العميل
                    </flux:label>

                    <flux:select
                        wire:model="customer_id"
                        id="customer_id"
                    >

                        <option value="">
                            اختر العميل
                        </option>

                        @foreach($this->customers as $cust)

                            <option value="{{ $cust->id }}">

                                {{ $cust->name }}

                                @if($cust->phone)
                                    - {{ $cust->phone }}
                                @endif

                            </option>

                        @endforeach

                    </flux:select>

                </flux:field>

            </div>


            {{-- Currency --}}

            <div>

                <flux:field>

                    <flux:label>
                        العملة
                    </flux:label>

                    <flux:select
                        wire:model="currency"
                    >

                        <option value="USD">
                            USD
                        </option>

                        <option value="ILS">
                            ILS
                        </option>

                        <option value="JOD">
                            JOD
                        </option>

                    </flux:select>

                </flux:field>

            </div>

        </div>


        {{-- ========================================================= --}}
        {{-- Add Product --}}
        {{-- ========================================================= --}}

        <div
            class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm"
        >

            <flux:heading
                size="lg"
                class="mb-4"
            >
                إضافة صنف
            </flux:heading>


            <div
                class="grid grid-cols-1 gap-4 md:grid-cols-5"
            >

                {{-- Barcode --}}

                <div>

                    <flux:field>

                        <flux:label>
                            الباركود
                        </flux:label>

                        <flux:input
                            id="item_barcode"
                            wire:model="item_barcode"
                            wire:keydown.enter="scanBarcode"
                            placeholder="امسح الباركود..."
                            autocomplete="off"
                        />

                    </flux:field>

                </div>


                {{-- Product --}}

                <div>

                    <flux:field>

                        <flux:label>
                            الصنف
                        </flux:label>

                        <flux:select
                            id="selected_product_id"
                            wire:model="selected_product_id"
                        >

                            <option value="">
                                اختر الصنف
                            </option>

                            @foreach($this->products as $product)

                                <option
                                    value="{{ $product->id }}"
                                >
                                    {{ $product->name }}
                                </option>

                            @endforeach

                        </flux:select>

                    </flux:field>

                </div>


                {{-- Quantity --}}

                <div>

                    <flux:field>

                        <flux:label>
                            الكمية
                        </flux:label>

                        <flux:input
                            id="item_quantity"
                            type="number"
                            min="0.001"
                            step="0.001"
                            wire:model="item_quantity"
                        />

                    </flux:field>

                </div>


                {{-- Price --}}

                <div>

                    <flux:field>

                        <flux:label>
                            السعر
                        </flux:label>

                        <flux:input
                            type="number"
                            min="0"
                            step="0.01"
                            wire:model="item_price"
                        />

                    </flux:field>

                </div>


                {{-- Add --}}

                <div class="flex items-end">

                    <flux:button
                        variant="primary"
                        wire:click="addItemRow"
                        class="w-full"
                    >
                        إضافة
                    </flux:button>

                </div>

            </div>

        </div>


        {{-- ========================================================= --}}
        {{-- Cart --}}
        {{-- ========================================================= --}}

        <div
            class="mb-6 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm"
        >

            <div class="overflow-x-auto">

                <table class="w-full text-sm">

                    <thead class="bg-zinc-50">

                        <tr>

                            <th class="px-4 py-3 text-right">
                                #
                            </th>

                            <th class="px-4 py-3 text-right">
                                الصنف
                            </th>

                            <th class="px-4 py-3 text-right">
                                الباركود
                            </th>

                            <th class="px-4 py-3 text-right">
                                الوحدة
                            </th>

                            <th class="px-4 py-3 text-right">
                                الكمية
                            </th>

                            <th class="px-4 py-3 text-right">
                                السعر
                            </th>

                            <th class="px-4 py-3 text-right">
                                الخصم
                            </th>

                            <th class="px-4 py-3 text-right">
                                الإجمالي
                            </th>

                            <th class="px-4 py-3 text-center">
                                حذف
                            </th>

                        </tr>

                    </thead>


                    <tbody
                        class="divide-y divide-zinc-100"
                    >

                        @forelse(
                            $cart
                            as $index => $item
                        )

                            <tr
                                wire:key="sale-item-{{ $index }}"
                            >

                                <td class="px-4 py-3">
                                    {{ $index + 1 }}
                                </td>


                                <td
                                    class="px-4 py-3 font-medium"
                                >
                                    {{ $item['name'] }}
                                </td>


                                <td
                                    class="px-4 py-3 text-zinc-500"
                                >
                                    {{ $item['barcode'] ?: '-' }}
                                </td>


                                <td class="px-4 py-3">
                                    {{ $item['unit'] }}
                                </td>


                                <td class="px-4 py-3">

                                    <input
                                        type="number"
                                        min="0.001"
                                        step="0.001"
                                        value="{{ $item['quantity'] }}"
                                        wire:change="updateCartQty(
                                            {{ $index }},
                                            $event.target.value
                                        )"
                                        class="w-24 rounded-lg border border-zinc-300 px-2 py-1"
                                    >

                                </td>


                                <td class="px-4 py-3">

                                    {{ number_format(
                                        $item['price'],
                                        2
                                    ) }}

                                </td>


                                <td class="px-4 py-3">

                                    {{ number_format(
                                        $item['discount'],
                                        2
                                    ) }}

                                </td>


                                <td
                                    class="px-4 py-3 font-semibold"
                                >

                                    {{ number_format(
                                        $item['subtotal'],
                                        2
                                    ) }}

                                </td>


                                <td
                                    class="px-4 py-3 text-center"
                                >

                                    <flux:button
                                        variant="danger"
                                        size="sm"
                                        wire:click="removeItemRow(
                                            {{ $index }}
                                        )"
                                    >
                                        حذف
                                    </flux:button>

                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td
                                    colspan="9"
                                    class="px-4 py-10 text-center text-zinc-500"
                                >
                                    لم تتم إضافة أي أصناف بعد.
                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

        </div>


        {{-- ========================================================= --}}
        {{-- Bottom --}}
        {{-- ========================================================= --}}

        <div
            class="grid grid-cols-1 gap-6 lg:grid-cols-2"
        >

            {{-- Discounts / Tax --}}

            <div
                class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm"
            >

                <flux:heading
                    size="lg"
                    class="mb-4"
                >
                    الخصومات والضريبة
                </flux:heading>


                <div class="space-y-4">

                    {{-- Percentage discount --}}

                    <flux:field>

                        <flux:label>
                            خصم بالنسبة المئوية
                        </flux:label>

                        <flux:input
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            wire:model.live="invoice_discount_percent"
                        />

                    </flux:field>


                    {{-- Value discount --}}

                    <flux:field>

                        <flux:label>
                            خصم بقيمة
                        </flux:label>

                        <flux:input
                            type="number"
                            min="0"
                            step="0.01"
                            wire:model.live="invoice_discount_val"
                        />

                    </flux:field>


                    {{-- Tax --}}

                    <flux:field>

                        <flux:label>
                            الضريبة %
                        </flux:label>

                        <flux:input
                            type="number"
                            min="0"
                            step="0.01"
                            wire:model.live="tax_percent"
                        />

                    </flux:field>


                    {{-- Cash --}}

                    <div class="flex items-center gap-3">

                        <input
                            type="checkbox"
                            wire:model.live="is_cash"
                            id="is_cash"
                            class="rounded border-zinc-300"
                        >

                        <label
                            for="is_cash"
                            class="text-sm"
                        >
                            دفع نقدي
                        </label>

                    </div>

                </div>

            </div>


            {{-- Summary --}}

            <div
                class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm"
            >

                <flux:heading
                    size="lg"
                    class="mb-4"
                >
                    ملخص الفاتورة
                </flux:heading>


                <div class="space-y-3">

                    {{-- Subtotal --}}

                    <div
                        class="flex justify-between"
                    >

                        <span class="text-zinc-500">
                            الإجمالي قبل الخصم
                        </span>

                        <span class="font-medium">

                            {{ number_format(
                                $this->subtotalBeforeDiscount,
                                2
                            ) }}

                            {{ $currency }}

                        </span>

                    </div>


                    {{-- Discount --}}

                    <div
                        class="flex justify-between"
                    >

                        <span class="text-zinc-500">
                            الخصم
                        </span>

                        <span
                            class="font-medium text-red-600"
                        >

                            -
                            {{ number_format(
                                $this->finalDiscount,
                                2
                            ) }}

                            {{ $currency }}

                        </span>

                    </div>


                    {{-- Tax --}}

                    <div
                        class="flex justify-between"
                    >

                        <span class="text-zinc-500">
                            الضريبة
                        </span>

                        <span class="font-medium">

                            {{ number_format(
                                $this->taxValue,
                                2
                            ) }}

                            {{ $currency }}

                        </span>

                    </div>


                    <div
                        class="my-4 border-t border-zinc-200"
                    ></div>


                    {{-- Total --}}

                    <div
                        class="flex justify-between text-lg"
                    >

                        <span class="font-semibold">
                            الإجمالي
                        </span>

                        <span class="font-bold">

                            {{ number_format(
                                $this->total,
                                2
                            ) }}

                            {{ $currency }}

                        </span>

                    </div>


                    {{-- Save --}}

                    <div class="pt-4">

                        <flux:button
                            variant="primary"
                            wire:click="saveInvoice"
                            wire:loading.attr="disabled"
                            class="w-full"
                        >

                            <span wire:loading.remove>
                                حفظ الفاتورة
                            </span>

                            <span wire:loading>
                                جارٍ الحفظ...
                            </span>

                        </flux:button>

                    </div>

                </div>

            </div>

        </div>


    </div>
</flux:main>
