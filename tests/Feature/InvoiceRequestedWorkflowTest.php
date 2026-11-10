<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الاختبار']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'الصنف', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);
});

test('editing an invoice line total recalculates and saves its unit price', function (string $page): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 3)
        ->call('commitRowToCart')
        ->assertSee('aria-label="إجمالي الصنف"', false);
    $key = array_key_first($component->get('cart'));

    $component->call('updateItemDiscount', $key, 2)
        ->call('updateItemTotal', $key, 10)
        ->assertSet("cart.$key.price", 4.0)
        ->assertSet("cart.$key.total_override", 10.0)
        ->call('saveInvoice')
        ->assertHasNoErrors()
        ->assertSet('errorMessage', null);

    $item = $page === 'sales' ? OrderItem::query()->sole() : PurchaseItem::query()->sole();
    expect((float) ($page === 'sales' ? $item->unit_price : $item->unit_cost))->toBe(4.0)
        ->and((float) ($page === 'sales' ? $item->total_price : $item->subtotal))->toBe(10.0);
})->with(['sales', 'purchases']);

test('invoice total changes preserve rounding and reject invalid input', function (string $page): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 3)
        ->call('commitRowToCart');
    $key = array_key_first($component->get('cart'));

    $component->call('updateItemTotal', $key, 10)
        ->assertSet("cart.$key.price", 3.33)
        ->assertSet("cart.$key.total_override", 10.0);

    foreach (['', 'invalid', -1, '1e999'] as $invalidTotal) {
        $component->call('updateItemTotal', $key, $invalidTotal)
            ->assertSet("cart.$key.price", 3.33)
            ->assertSet("cart.$key.total_override", 10.0);
    }

    $component->call('updateItemQuantity', $key, 2)
        ->assertSet("cart.$key.price", 3.33);
    expect($component->get('cart')[$key])->not->toHaveKey('total_override');
    $component->call('updateItemTotal', $key, 0)->assertSet("cart.$key.price", 0.0);
    $component->set("cart.$key.quantity", 0)
        ->call('updateItemTotal', $key, 20)
        ->assertSet("cart.$key.price", 0.0);
})->with(['sales', 'purchases']);

test('changing invoice quantity recalculates the line and invoice totals for sales and purchases', function (string $page, float $expectedTotal): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 3)
        ->call('commitRowToCart');
    $key = array_key_first($component->get('cart'));

    $component->assertSee('wire:input.debounce.300ms="updateItemQuantity', false)
        ->call('updateItemQuantity', $key, 2)
        ->assertViewHas('grandTotal', $expectedTotal);
})->with([
    ['sales', 20.0],
    ['purchases', 4.0],
]);

test('invoice item rows have prominent separators', function (string $page): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->assertSee('<tbody class="divide-y-2 divide-slate-500">', false);

    if ($page === 'purchases') {
        $component->assertSee('<div class="divide-y-2 divide-slate-500 md:hidden">', false);
    }
})->with(['sales', 'purchases']);

test('saving a sales or purchase invoice immediately starts a fresh invoice after printing the saved data', function (string $page, string $model, string $idField): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->set('invoiceDate', '2026-01-15')
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 3)->call('commitRowToCart');
    $number = $component->get('invoiceNumber');
    $component->call('saveAndPrint')->assertHasNoErrors()->assertSet('errorMessage', null)
        ->assertSet($idField, null)->assertSet('cart', [])->assertSet('invoiceEditMode', true)
        ->assertDispatched($page === 'sales' ? 'print-sales-invoice' : 'print-purchase-invoice');
    expect($model::query()->count())->toBe(1)
        ->and($model::query()->sole()->invoice_date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($component->get('invoiceNumber'))->not->toBe($number);
    $component->call('saveInvoice');
    expect($model::query()->count())->toBe(1);
})->with([
    ['sales', Order::class, 'currentOrderId'],
    ['purchases', Purchase::class, 'currentPurchaseId'],
]);

test('new sales invoice records only the entered payment as a receipt voucher', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون مدفوعات البيع', 'type' => 'customer']);
    $component = Livewire::test('pages::tenant.sales.invoices')
        ->set('selectedCustomerId', $customer->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $order = Order::query()->sole();
    expect((float) $order->paid_amount)->toBe(0.0)
        ->and((float) $customer->fresh()->current_balance)->toBe(10.0)
        ->and(Payment::query()->where('order_id', $order->id)->exists())->toBeFalse();

    $component->call('startNewInvoice', false)
        ->set('selectedCustomerId', $customer->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->set('paidAmount', 4)
        ->set('paymentMethod', 'card')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $paidOrder = Order::query()->orderByDesc('id')->firstOrFail();
    $receipt = Payment::query()->where('order_id', $paidOrder->id)->sole();
    expect($receipt->type)->toBe('receipt')
        ->and((float) $receipt->amount)->toBe(4.0)
        ->and($receipt->payment_method)->toBe('card')
        ->and((float) $customer->fresh()->current_balance)->toBe(16.0);
});

test('new purchase invoice records only the entered payment as a payment voucher', function (): void {
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'المورد', 'type' => 'supplier']);
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->set('selectedSupplierId', $supplier->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->set('paidAmount', 1.5)
        ->set('paymentMethod', 'bank_transfer')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $purchase = Purchase::query()->sole();
    $voucher = Payment::query()->where('purchase_id', $purchase->id)->sole();
    expect($voucher->type)->toBe('payment')
        ->and((float) $voucher->amount)->toBe(1.5)
        ->and($voucher->payment_method)->toBe('bank_transfer')
        ->and((float) $purchase->paid_amount)->toBe(1.5);
});

test('new purchase invoice accepts a payment above its total and credits the supplier balance', function (): void {
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد دفع زائد', 'type' => 'supplier']);
    Livewire::test('pages::tenant.purchases.invoices')
        ->set('selectedSupplierId', $supplier->id)
        ->call('selectProduct', $this->product->id)
        ->set('selectedPurchasePrice', 10)
        ->call('commitRowToCart')
        ->set('paidAmount', 15)
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $purchase = Purchase::query()->sole();
    $payment = Payment::query()->where('purchase_id', $purchase->id)->sole();
    expect((float) $purchase->total)->toBe(10.0)
        ->and((float) $purchase->paid_amount)->toBe(10.0)
        ->and($purchase->payment_status)->toBe('paid')
        ->and((float) $payment->amount)->toBe(15.0)
        ->and((float) $supplier->fresh()->current_balance)->toBe(-5.0);
});

test('new sales invoice keeps an overpayment as a linked receipt voucher and customer credit', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون دفعة زائدة', 'type' => 'customer']);
    Livewire::test('pages::tenant.sales.invoices')
        ->set('selectedCustomerId', $customer->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->set('paidAmount', 15)
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $order = Order::query()->sole();
    $receipt = Payment::query()->where('order_id', $order->id)->sole();
    expect((float) $order->total)->toBe(10.0)
        ->and((float) $order->paid_amount)->toBe(10.0)
        ->and($order->payment_status)->toBe('paid')
        ->and((float) $receipt->amount)->toBe(15.0)
        ->and((float) $customer->fresh()->current_balance)->toBe(-5.0);
});

test('settlement vouchers validate and save optional notes without reusing previous notes', function (string $page, string $partyType, string $partyField): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'طرف سند التسديد', 'type' => $partyType]);
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->set($partyField, $party->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->set('settlementNotes', 'ملاحظات سابقة')
        ->call('saveInvoice', false, true)
        ->assertSet('showSettlementModal', true)
        ->assertSet('settlementNotes', '')
        ->assertSee('wire:model="settlementNotes"', false)
        ->set('settlementAmount', 1)
        ->set('settlementNotes', str_repeat('أ', 5001))
        ->call('saveSettlementVoucher')
        ->assertHasErrors(['settlementNotes' => 'max']);

    expect(Payment::query()->count())->toBe(0);

    $component->set('settlementNotes', "  دفعة أولى\nبموجب الاتفاق  ")
        ->call('saveSettlementVoucher')
        ->assertHasNoErrors()
        ->assertSet('showSettlementModal', false);

    expect(Payment::query()->sole()->notes)->toBe("دفعة أولى\nبموجب الاتفاق");
})->with([
    ['sales', 'customer', 'selectedCustomerId'],
    ['purchases', 'supplier', 'selectedSupplierId'],
]);

test('save and settle creates a linked receipt for any entered amount', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون تسديد الفاتورة', 'type' => 'customer']);
    $component = Livewire::test('pages::tenant.sales.invoices')
        ->set('selectedCustomerId', $customer->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->call('saveInvoice', false, true)
        ->assertHasNoErrors()
        ->assertSet('showSettlementModal', true);

    $order = Order::query()->sole();
    $component->set('settlementAmount', 25)
        ->set('settlementMethod', 'card')
        ->call('saveSettlementVoucher')
        ->assertHasNoErrors()
        ->assertSet('showSettlementModal', false);

    $receipt = Payment::query()->where('order_id', $order->id)->sole();
    expect((float) $receipt->amount)->toBe(25.0)
        ->and($receipt->type)->toBe('receipt')
        ->and($receipt->payment_method)->toBe('card')
        ->and($receipt->notes)->toBe('سند قبض مرتبط بفاتورة المبيعات '.$order->invoice_number)
        ->and((float) $order->fresh()->paid_amount)->toBe(10.0)
        ->and((float) $customer->fresh()->current_balance)->toBe(-15.0);
});

test('save and settle creates a linked payment for any entered amount', function (): void {
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد تسديد الفاتورة', 'type' => 'supplier']);
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->set('selectedSupplierId', $supplier->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->call('saveInvoice', false, true)
        ->assertHasNoErrors()
        ->assertSet('showSettlementModal', true);

    $purchase = Purchase::query()->sole();
    $component->set('settlementAmount', 25)
        ->set('paymentMethod', 'card')
        ->call('saveSettlementVoucher')
        ->assertHasNoErrors()
        ->assertSet('showSettlementModal', false);

    $payment = Payment::query()->where('purchase_id', $purchase->id)->sole();
    expect((float) $payment->amount)->toBe(25.0)
        ->and($payment->type)->toBe('payment')
        ->and($payment->payment_method)->toBe('card')
        ->and($payment->notes)->toBe('سند صرف مرتبط بفاتورة المشتريات '.$purchase->reference_number)
        ->and((float) $purchase->fresh()->paid_amount)->toBe(2.0)
        ->and((float) $supplier->fresh()->current_balance)->toBe(-23.0);
});

test('editing a mobile invoice keeps its linked overpayment as the paid amount source', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون فاتورة التطبيق', 'type' => 'customer', 'current_balance' => -50]);
    $order = Order::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'invoice_number' => 'APP-PAID-EDIT',
        'type' => 'wholesale',
        'source' => 'api',
        'status' => 'completed',
        'subtotal' => 100,
        'tax_percent' => 0,
        'total' => 100,
        'paid_amount' => 100,
        'payment_status' => 'paid',
    ]);
    OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 10,
        'unit_price' => 10,
        'total_price' => 100,
        'cost_price' => 2,
        'total_cost' => 20,
        'discount' => 0,
    ]);
    $receipt = Payment::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'party_id' => $customer->id,
        'type' => 'receipt',
        'voucher_number' => 'APP-PAID-EDIT-RCV',
        'amount' => 150,
        'payment_method' => 'cash',
        'order_id' => $order->id,
        'payment_date' => now(),
    ]);
    DB::table('payments')->where('id', $receipt->id)->update(['updated_at' => now()->subDay()]);
    $receiptUpdatedAt = $receipt->fresh()->updated_at;

    $component = Livewire::test('pages::tenant.sales.invoices')
        ->call('loadOrder', $order->id)
        ->assertSee('150.00')
        ->assertSee(route('tenant.receipts', ['voucher' => $receipt->id]), false)
        ->call('editLoadedInvoice');
    $lineKey = array_key_first($component->get('cart'));
    $component->set("cart.{$lineKey}.price", 12)
        ->call('saveInvoice')
        ->assertSet('errorMessage', null)
        ->assertSet('savedPaidAmount', 120);

    expect((float) $order->fresh()->total)->toBe(120.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(120.0)
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and((float) $receipt->fresh()->amount)->toBe(150.0)
        ->and((int) $receipt->fresh()->party_id)->toBe($customer->id)
        ->and($receipt->fresh()->updated_at->equalTo($receiptUpdatedAt))->toBeTrue()
        ->and((float) $customer->fresh()->current_balance)->toBe(-30.0);
});

test('owner can reconcile a customer balance to the account statement movements', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون فرق الرصيد', 'type' => 'customer', 'current_balance' => -195]);
    $order = Order::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'invoice_number' => 'SAL-RECONCILE-195',
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 195,
        'total' => 195,
        'paid_amount' => 195,
    ]);
    Payment::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'party_id' => $customer->id,
        'type' => 'receipt',
        'voucher_number' => 'RCV-RECONCILE-195',
        'amount' => 195,
        'payment_method' => 'cash',
        'order_id' => $order->id,
        'payment_date' => now(),
    ]);

    Livewire::test('pages::tenant.customers.index')
        ->call('openStatementModal', $customer->id)
        ->assertSee('195.00')
        ->call('reconcilePartyBalance', $customer->id)
        ->assertDispatched('toast', type: 'success', message: 'تمت تسوية الرصيد مع حركات كشف الحساب.');

    expect((float) $customer->fresh()->current_balance)->toBe(0.0);
});

test('saving a sales or purchase invoice without printing starts a fresh invoice', function (string $page, string $model, string $idField): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->set('invoiceDate', '2026-01-15')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart');
    $savedNumber = $component->get('invoiceNumber');

    $component->call('saveInvoice')
        ->assertHasNoErrors()
        ->assertSet($idField, null)
        ->assertSet('cart', [])
        ->assertSet('invoiceEditMode', true)
        ->assertSee('وفتح فاتورة جديدة');

    expect($model::query()->count())->toBe(1)
        ->and($model::query()->sole()->invoice_date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($component->get('invoiceNumber'))->not->toBe($savedNumber);
})->with([
    ['sales', Order::class, 'currentOrderId'],
    ['purchases', Purchase::class, 'currentPurchaseId'],
]);

test('sales and purchase invoice totals include the tax already present in item prices', function (string $page, string $model, string $priceField, string $taxField): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->call('selectProduct', $this->product->id)
        ->set($priceField, 116)
        ->call('commitRowToCart')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $invoice = $model::query()->sole();

    expect((float) $invoice->total)->toBe(116.0)
        ->and((float) $invoice->$taxField)->toBe(16.0);
})->with([
    ['sales', Order::class, 'selectedPrice', 'tax_amount'],
    ['purchases', Purchase::class, 'selectedPurchasePrice', 'tax_amount'],
]);

test('purchase invoice product search shows results and adds the chosen item', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->set('rowSearch', 'الصنف')
        ->assertViewHas('productResults', fn ($results): bool => $results->pluck('id')->all() === [$this->product->id])
        ->assertSee('data-invoice-search-results', false)
        ->assertSee('wire:click="addProductFromSearch('.$this->product->id.')"', false)
        ->call('addProductFromSearch', $this->product->id);

    expect(array_values($component->get('cart'))[0]['id'])->toBe($this->product->id);
});

test('purchase invoice search opens a saved invoice by its reference', function (): void {
    $purchase = Purchase::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'reference_number' => 'PUR-SEARCH-42',
        'total' => 10,
        'paid_amount' => 0,
        'payment_status' => 'unpaid',
    ]);

    Livewire::test('pages::tenant.purchases.invoices')
        ->call('togglePurchaseSearch')
        ->set('searchPurchaseQuery', 'PUR-SEARCH-42')
        ->call('searchPurchase')
        ->assertSet('currentPurchaseId', $purchase->id)
        ->assertSet('searchPurchaseQuery', '')
        ->assertSet('showPurchaseSearch', false);
});

test('scanning the printed invoice barcode opens that exact invoice without an open shift', function (): void {
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'POS-000123', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
    Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'POS-0001230', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
    Shift::query()->update(['status' => 'closed']);
    Livewire::test('pages::tenant.pos.sales')->call('scanBarcode', $order->invoice_number)
        ->assertSet('currentInvoiceId', $order->id)->assertSet('invoiceEditMode', false)->assertSet('showMissingBarcodeModal', false);
    Livewire::test('pages::tenant.pos.sales')->set('searchInvoiceQuery', 'POS-٠٠٠١٢٣')->call('searchInvoice')
        ->assertSet('currentInvoiceId', $order->id);
});

test('delivery notes survive checkout and appear on the printed receipt even for free delivery', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->set('notes', 'ملاحظة الفاتورة')->set('deliveryNotes', 'اتصل قبل الوصول')
        ->assertSee('اتصل قبل الوصول')->set('paid_amount', 10)->call('checkoutAndPrint')
        ->assertDispatched('print-receipt')->assertSet('errorMessage', null);
    $order = Order::query()->sole();
    expect($order->notes)->toContain('ملاحظة الفاتورة', 'ملاحظة التوصيل: اتصل قبل الوصول');
    expect($component->get('receipt')['notes'])->toContain('اتصل قبل الوصول');
    expect(view('pages.tenant.pos.partials.thermal-receipt', ['receipt' => $component->get('receipt')])->render())->toContain('اتصل قبل الوصول');

    $component->call('loadInvoice', $order->id)
        ->assertSet('notes', 'ملاحظة الفاتورة')
        ->assertSet('deliveryNotes', 'اتصل قبل الوصول');

    expect(view('pages.tenant.pos.partials.invoice-pdf', ['order' => $order->fresh()->load('items.product')])->render())
        ->toContain('ملاحظة التوصيل: اتصل قبل الوصول');
});

test('product history shows date quantity party and invoice links for both sales and purchases', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون الحركة', 'type' => 'customer']);
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد الحركة', 'type' => 'supplier']);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'invoice_number' => 'POS-HISTORY', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 30, 'total' => 30, 'paid_amount' => 30]);
    $order->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10, 'total_price' => 30, 'cost_price' => 2, 'total_cost' => 6]);
    $purchase = Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id, 'reference_number' => 'PUR-HISTORY', 'total' => 20, 'paid_amount' => 20, 'payment_status' => 'paid']);
    $purchase->items()->create(['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 2, 'subtotal' => 20]);
    Livewire::test('product-history')->dispatch('show-product-history', productId: $this->product->id)
        ->assertSet('showHistory', true)->assertSee('زبون الحركة')->assertSee('مورد الحركة')->assertSee('3.00')->assertSee('10.00')
        ->assertSee(route('tenant.pos', ['invoice' => $order->id]), false)->assertSee(route('tenant.purchase-invoices', ['invoice' => $purchase->id]), false);
    $purchase->delete();
    Livewire::test('product-history')->call('openHistory', $this->product->id)->assertDontSee('PUR-HISTORY');
});

test('product history rejects another tenants product', function (): void {
    $otherTenant = Tenant::create(['name' => 'متجر آخر']);
    $product = Product::create(['tenant_id' => $otherTenant->id, 'name' => 'صنف مخفي']);
    Livewire::test('product-history')->call('openHistory', $product->id)->assertNotFound();
});

test('product history excludes invoices from other branches and tenants', function (): void {
    $otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'فرع آخر']);
    $otherTenant = Tenant::create(['name' => 'متجر آخر']);
    foreach ([$otherBranch, Branch::create(['tenant_id' => $otherTenant->id, 'name' => 'فرع مخفي'])] as $branch) {
        $product = $branch->tenant_id === $this->tenant->id ? $this->product : Product::create(['tenant_id' => $branch->tenant_id, 'name' => 'صنف مخفي']);
        $order = Order::create(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'invoice_number' => 'HIDDEN-'.$branch->id, 'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
        $order->items()->create(['tenant_id' => $branch->tenant_id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'cost_price' => 2, 'total_cost' => 2]);
    }
    Livewire::test('product-history')->call('openHistory', $this->product->id)->assertDontSee('HIDDEN-')->assertSee('لا توجد حركة');
});

test('party report shortcut opens the selected customer or supplier statement', function (string $type): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'طرف التقرير', 'type' => $type]);
    Livewire::withQueryParams(['report' => 'statement', 'party_id' => $party->id, 'party_type' => $type])
        ->test('pages::tenant.reports.center')->assertSet('partyId', $party->id)->assertSet('statementPartyType', $type);
    Livewire::withQueryParams([])->test('pages::tenant.'.($type === 'customer' ? 'sales' : 'purchases').'.invoices')
        ->set($type === 'customer' ? 'selectedCustomerId' : 'selectedSupplierId', $party->id)
        ->assertSee(route('tenant.reports', ['report' => 'statement', 'party_id' => $party->id, 'party_type' => $type]));
})->with(['customer', 'supplier']);

test('notes button and dialog share the workspace Alpine state', function (): void {
    $toolbar = file_get_contents(resource_path('views/pages/tenant/pos/partials/toolbar.blade.php'));
    expect($toolbar)->not->toContain('mobileToolsOpen: false, notesOpen: false')
        ->and($toolbar)->toContain('x-on:click="notesOpen = true"', 'x-show="notesOpen"');
});
