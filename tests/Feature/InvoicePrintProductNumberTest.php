<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الطباعة']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'فانوس']);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => 'tenant_user', 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'كيس غسيل ملابس قماش', 'product_number' => 'I00000207']);
});

test('printed sales invoices show the product number and are titled with the customer name', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'ابو هاشم ياسين', 'type' => 'customer']);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
        'invoice_number' => 'SAL-PRINT-1', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 523, 'discount' => 0, 'total' => 523, 'paid_amount' => 0]);
    $order->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 523, 'total_price' => 523, 'cost_price' => 0, 'total_cost' => 0]);

    Livewire::test('pages::tenant.sales.invoices')->set('currentOrderId', $order->id)->call('printCurrentInvoice')
        ->assertDispatched('print-sales-invoice', fn (string $name, array $params): bool => $params['invoice']['items'][0]['product_number'] === 'I00000207'
            && $params['invoice']['file_title'] === 'ابو هاشم ياسين - SAL-PRINT-1')
        ->assertSee('رقم الصنف');
});

test('printed purchase invoices show the product number and are titled with the supplier name', function (): void {
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد الطباعة', 'type' => 'supplier']);
    $id = DB::table('purchases')->insertGetId(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id,
        'reference_number' => 'PUR-PRINT-1', 'total' => 20, 'paid_amount' => 20, 'payment_status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_items')->insert(['purchase_id' => $id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 10, 'subtotal' => 20, 'created_at' => now(), 'updated_at' => now()]);

    Livewire::test('pages::tenant.purchases.invoices')->set('currentPurchaseId', $id)->call('printCurrentInvoice')
        ->assertDispatched('print-purchase-invoice', fn (string $name, array $params): bool => $params['invoice']['items'][0]['product_number'] === 'I00000207'
            && $params['invoice']['file_title'] === 'مورد الطباعة - PUR-PRINT-1');
});

test('invoice prints use the same PDF as the download, shown inline for printing', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون الطباعة', 'type' => 'customer']);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
        'invoice_number' => 'SAL-INLINE', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'discount' => 0, 'total' => 10, 'paid_amount' => 0]);
    $order->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'cost_price' => 0, 'total_cost' => 0]);
    $url = route('tenant.sale-invoices.pdf', ['orderId' => $order->id]);

    Livewire::test('pages::tenant.sales.invoices')->set('currentOrderId', $order->id)->call('printCurrentInvoice')
        ->assertDispatched('print-sales-invoice', fn (string $name, array $params): bool => $params['invoice']['pdf_url'] === $url);
    Livewire::test('pages::tenant.wholesale.invoices')->call('printOrder', $order->id)
        ->assertDispatched('print-wholesale-invoice', fn (string $name, array $params): bool => $params['data']['pdf_url'] === $url);

    $inline = $this->get($url.'?inline=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($inline->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($inline->streamedContent())->toStartWith('%PDF');
    expect($this->get($url)->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain("filename*=utf-8''".rawurlencode('زبون الطباعة - SAL-INLINE.pdf'));
    expect($inline->headers->get('Content-Disposition'))->toContain("filename*=utf-8''".rawurlencode('زبون الطباعة - SAL-INLINE.pdf'));
});

test('purchase prints carry their PDF and return prints request the return PDF', function (): void {
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد الطباعة', 'type' => 'supplier']);
    $id = DB::table('purchases')->insertGetId(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id,
        'reference_number' => 'PUR-INLINE', 'total' => 20, 'paid_amount' => 20, 'payment_status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_items')->insert(['purchase_id' => $id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 10, 'subtotal' => 20, 'created_at' => now(), 'updated_at' => now()]);

    Livewire::test('pages::tenant.purchases.invoices')->set('currentPurchaseId', $id)->call('printCurrentInvoice')
        ->assertDispatched('print-purchase-invoice', fn (string $name, array $params): bool => $params['invoice']['pdf_url'] === route('tenant.purchase-invoices.pdf', ['purchaseId' => $id]));

    $returnId = DB::table('purchase_returns')->insertGetId(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id,
        'reference_number' => 'PR-INLINE', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_return_items')->insert(['tenant_id' => $this->tenant->id, 'purchase_return_id' => $returnId, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10, 'created_at' => now(), 'updated_at' => now()]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 5]);

    Livewire::test('pages::tenant.returns.index')->set('kind', 'purchase')->call('loadReturn', $returnId)->call('printCurrentInvoice')
        ->assertDispatched('print-pdf', url: route('tenant.returns.pdf', ['kind' => 'purchase', 'returnId' => $returnId]));
});

test('pdf titles keep arabic text readable in every invoice and voucher pdf', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'ابو هاشم ياسين', 'type' => 'customer']);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
        'invoice_number' => 'SAL-UTF8', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'discount' => 0, 'total' => 10, 'paid_amount' => 0]);
    $order->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'cost_price' => 0, 'total_cost' => 0]);
    $purchase = (object) ['tenant_id' => $this->tenant->id, 'reference_number' => 'PUR-UTF8', 'invoice_date' => now()->toDateString(), 'created_at' => now(), 'total' => 10, 'paid_amount' => 0, 'tax_amount' => 0, 'discount_value' => 0, 'notes' => null, 'supplier_id' => null];
    $voucher = Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $customer->id, 'type' => 'receipt', 'voucher_number' => 'RCV-UTF8', 'amount' => 5, 'payment_method' => 'cash', 'payment_date' => now()]);
    $return = (object) ['invoice_number' => 'RET-UTF8', 'reference_number' => 'RET-UTF8', 'created_at' => now(), 'subtotal' => 10, 'discount' => 0, 'tax_amount' => 0, 'total' => 10, 'paid_amount' => 0, 'notes' => 'ملاحظة'];

    $documents = [
        'pages.tenant.pos.partials.invoice-pdf' => ['order' => $order->load('items.product'), 'accountBalance' => 0, 'storeName' => 'فانوس'],
        'pages.tenant.purchases.partials.invoice-pdf' => ['purchase' => $purchase, 'items' => collect(), 'supplierName' => 'مورد', 'accountBalance' => null, 'storeName' => 'فانوس'],
        'pages.tenant.accounting.partials.voucher-pdf' => ['voucher' => $voucher->load(['party', 'creator', 'branch', 'tenant'])],
        'pages.tenant.returns.partials.invoice-pdf' => ['return' => $return, 'items' => collect(), 'party' => $customer, 'branchName' => 'فانوس', 'kind' => 'sale', 'showTax' => true],
    ];

    foreach ($documents as $view => $data) {
        $pdf = app('dompdf.wrapper')->loadView($view, $data + ['documentTitle' => 'ابو هاشم ياسين - SAL-UTF8']);
        $text = $pdf->getDomPDF()->getDom()->textContent;
        expect($text)->not->toContain('Ø')->not->toContain('ï»')
            ->and($pdf->getDomPDF()->getDom()->getElementsByTagName('title')->item(0)?->textContent)->toBe('ابو هاشم ياسين - SAL-UTF8');
    }
});

test('saved invoices and returns print without switching to edit mode', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون العرض', 'type' => 'customer']);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
        'invoice_number' => 'SAL-VIEW', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'discount' => 0, 'total' => 10, 'paid_amount' => 0]);
    $order->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'cost_price' => 0, 'total_cost' => 0]);

    Livewire::test('pages::tenant.sales.invoices')->call('openInvoiceFromSearch', $order->id)->assertSet('invoiceEditMode', false)
        ->assertSee('طباعة')->call('saveAndPrint')
        ->assertSet('errorMessage', null)->assertDispatched('print-sales-invoice')->assertSet('invoiceEditMode', false);

    $id = DB::table('purchases')->insertGetId(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'reference_number' => 'PUR-VIEW',
        'total' => 20, 'paid_amount' => 20, 'payment_status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
    Livewire::test('pages::tenant.purchases.invoices')->set('currentPurchaseId', $id)->set('invoiceEditMode', false)->call('saveAndPrint')
        ->assertSet('errorMessage', null)->assertDispatched('print-purchase-invoice');

    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد العرض', 'type' => 'supplier']);
    $returnId = DB::table('purchase_returns')->insertGetId(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id,
        'reference_number' => 'PR-VIEW', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_return_items')->insert(['tenant_id' => $this->tenant->id, 'purchase_return_id' => $returnId, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10, 'created_at' => now(), 'updated_at' => now()]);
    Livewire::test('pages::tenant.returns.index')->set('kind', 'purchase')->call('loadReturn', $returnId)->assertSet('editing', false)
        ->assertSeeHtml('(F6)')->call('saveReturn', true)
        ->assertSet('errorMessage', null)->assertSet('editing', false)
        ->assertDispatched('print-pdf', url: route('tenant.returns.pdf', ['kind' => 'purchase', 'returnId' => $returnId]));
});
