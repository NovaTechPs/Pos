<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceBalanceSummary;
use App\Services\PdfFilename;
use ArPHP\I18N\Arabic;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الاختبار']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الأول']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

test('pdf file names use the party name then the document number', function (): void {
    expect(PdfFilename::forDocument('محمد للتجارة', 'SAL-1', 'زبون عام'))->toBe('محمد للتجارة - SAL-1.pdf')
        ->and(PdfFilename::forDocument(null, 'SAL-2', 'زبون عام'))->toBe('زبون عام - SAL-2.pdf')
        ->and(PdfFilename::forDocument('  شركة/النور: "فرع"  ', 'PUR?3', 'مورد عام'))->toBe('شركة-النور- -فرع - PUR-3.pdf');
});

test('sales invoice pdf shows item numbers in right-to-left column order with latin digits', function (): void {
    $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'تيرموس قهوة 20 شيكل', 'product_number' => 'I00000022']);
    $order = new Order(['invoice_number' => 'SAL-77', 'type' => 'wholesale', 'customer_name' => 'محمد', 'subtotal' => 40, 'total' => 40, 'discount' => 0, 'paid_amount' => 40]);
    $item = new OrderItem(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20, 'total_price' => 40]);
    $item->setRelation('product', $product);
    $order->setRelation('items', collect([$item]));
    $order->setRelation('user', null);

    $html = view('pages.tenant.pos.partials.invoice-pdf', ['order' => $order, 'storeName' => 'متجر'])->render();
    $arabic = new Arabic;

    expect($html)->toContain('I00000022')
        ->toContain(e($arabic->utf8Glyphs('تيرموس قهوة 20 شيكل', 45, false, true)))
        ->not->toContain('٢٠')
        ->and(strpos($html, $arabic->utf8Glyphs('اسم الصنف', 150, false, true)))
        ->toBeLessThan(strpos($html, $arabic->utf8Glyphs('رقم الصنف', 150, false, true)));
});

test('purchase invoice pdf shows the product number, falling back to the product id', function (): void {
    $purchase = (object) ['reference_number' => 'PUR-9', 'invoice_date' => now()->toDateString(), 'created_at' => now(), 'total' => 30, 'paid_amount' => 0];
    $items = collect([
        (object) ['product_id' => 5, 'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10, 'product_name' => 'صنف أ', 'product_number' => 'P-0005'],
        (object) ['product_id' => 6, 'quantity' => 2, 'unit_cost' => 10, 'subtotal' => 20, 'product_name' => 'صنف ب', 'product_number' => null],
    ]);

    $html = view('pages.tenant.purchases.partials.invoice-pdf', ['purchase' => $purchase, 'items' => $items, 'supplierName' => 'المورد', 'storeName' => 'متجر'])->render();

    expect($html)->toContain('P-0005')->toContain('<td class="num">6</td>');
});

test('invoice and voucher pdf downloads are named after the party and the document number', function (): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'أبو أحمد للتجارة', 'type' => 'both', 'current_balance' => 0]);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $party->id, 'customer_name' => 'أبو أحمد للتجارة', 'invoice_number' => 'SAL-500', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
    $purchase = Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $party->id, 'reference_number' => 'PUR-500', 'total' => 10, 'paid_amount' => 10]);
    $receipt = Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $party->id, 'created_by' => $this->user->id, 'type' => 'receipt', 'amount' => 5, 'payment_method' => 'cash', 'payment_date' => now(), 'voucher_number' => 'RV-500']);
    $payment = Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $party->id, 'created_by' => $this->user->id, 'type' => 'payment', 'amount' => 5, 'payment_method' => 'cash', 'payment_date' => now(), 'voucher_number' => 'PV-500']);

    $downloads = [
        route('tenant.sale-invoices.pdf', ['orderId' => $order->id]) => 'أبو أحمد للتجارة - SAL-500.pdf',
        route('tenant.purchase-invoices.pdf', ['purchaseId' => $purchase->id]) => 'أبو أحمد للتجارة - PUR-500.pdf',
        route('tenant.vouchers.pdf', ['type' => 'receipt', 'paymentId' => $receipt->id]) => 'أبو أحمد للتجارة - RV-500.pdf',
        route('tenant.vouchers.pdf', ['type' => 'payment', 'paymentId' => $payment->id]) => 'أبو أحمد للتجارة - PV-500.pdf',
    ];

    foreach ($downloads as $url => $filename) {
        // Browsers use the UTF-8 file name; the plain "filename=" part is only an ASCII fallback.
        expect($this->get($url)->assertOk()->headers->get('content-disposition'))
            ->toContain("filename*=utf-8''".rawurlencode($filename));
    }
});

test('invoice prints show only total, discount, payment, previous and new balance', function (): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'أبو أحمد', 'type' => 'customer', 'current_balance' => 150]);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $party->id, 'customer_name' => 'أبو أحمد',
        'invoice_number' => 'SAL-900', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 110, 'discount' => 10, 'total' => 100, 'paid_amount' => 40]);

    expect(InvoiceBalanceSummary::forOrder($order))->toBe([
        'total' => 110.0, 'discount' => 10.0, 'payment' => 40.0, 'previous_balance' => 90.0, 'new_balance' => 150.0,
    ]);

    $arabic = new Arabic;
    $html = view('pages.tenant.pos.partials.invoice-pdf', ['order' => $order->load('items.product'), 'storeName' => 'متجر'])->render();
    $summary = substr($html, strpos($html, 'data-invoice-balance-summary'));
    foreach (['الزبون', 'المجموع', 'الخصم', 'دفعة', 'رصيد سابق', 'رصيد جديد'] as $label) {
        expect($html)->toContain($arabic->utf8Glyphs($label, 150, false, true));
    }
    expect($summary)->toContain('110.00', '10.00', '40.00', '90.00', '150.00');
    foreach (['العميل', 'الإجمالي النهائي', 'المتبقي من الفاتورة', 'عدد الأصناف', 'إجمالي الكمية', 'شكراً لتعاملكم معنا'] as $removed) {
        expect($html)->not->toContain($arabic->utf8Glyphs($removed, 150, false, true));
    }

    Livewire::test('pages::tenant.sales.invoices')
        ->set('currentOrderId', $order->id)
        ->call('printCurrentInvoice')
        ->assertDispatched('print-sales-invoice', fn (string $event, array $params): bool => (float) $params['invoice']['summary']['previous_balance'] === 90.0
            && (float) $params['invoice']['summary']['new_balance'] === 150.0);
});
