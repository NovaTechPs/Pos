<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الاختبار']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الأول']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

test('offers optionally update only the selected branch retail price', function (string $page, bool $updatePrice): void {
    $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'الصنف', 'cost_price' => 5]);
    $otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الثاني']);
    foreach ([$this->branch, $otherBranch] as $branch) {
        BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'retail_price' => 20, 'wholesale_price' => 15, 'stock_quantity' => 7]);
    }
    $component = Livewire::test('pages::tenant.'.$page.'.index');
    if ($page === 'products') {
        $component->call('openOfferModal', $product->id)->set('offerQuantity', '3')->set('offerPrice', '45');
    } else {
        $component->call('openCreateModal')->call('selectProduct', $product->id)->set('quantity', '3')->set('price', '45')->set('startAt', now()->format('Y-m-d\TH:i'))->set('hasEndDate', false);
    }
    $component->assertSee('تعديل سعر البيع لهذا الفرع')->assertSet('offerRetailPrice', '20')
        ->set('updateOfferRetailPrice', $updatePrice)->set('offerRetailPrice', '25')
        ->call($page === 'products' ? 'saveOffer' : 'save')->assertHasNoErrors();
    expect(ProductOffer::query()->count())->toBe(1);
    $prices = BranchProduct::query()->where('product_id', $product->id)->get()->keyBy('branch_id');
    expect((float) $prices[$this->branch->id]->retail_price)->toBe($updatePrice ? 25.0 : 20.0)
        ->and((float) $prices[$otherBranch->id]->retail_price)->toBe(20.0)
        ->and((float) $prices[$this->branch->id]->wholesale_price)->toBe(15.0)
        ->and((float) $prices[$this->branch->id]->stock_quantity)->toBe(7.0);
})->with(['products', 'offers'])->with([true, false]);

test('delivery details use invoice recipient fields and keep customer records managed separately', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون التوصيل', 'type' => 'customer', 'phone' => '0591000001', 'address' => 'الخليل', 'is_active' => true]);
    $company = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'شركة التوصيل', 'type' => 'supplier', 'is_active' => true]);

    $component = Livewire::test('pages::tenant.pos.sales')
        ->set('selectedCustomerId', $customer->id)
        ->call('openDeliveryDetails')
        ->assertDontSee('اختيار من الموردين والزبائن')
        ->assertSee('wire:model="deliveryCustomerName"', false)
        ->assertSee('wire:model="deliveryCustomerPhone"', false)
        ->assertSee('wire:model="deliveryCustomerAddress"', false)
        ->assertSee($company->name)
        ->assertSee($customer->name)
        ->assertSee('ابحث بالاسم أو الهاتف')
        ->set('deliveryCustomerName', 'اسم للمستلم بالفاتورة');

    expect($customer->fresh()->name)->toBe('زبون التوصيل')
        ->and($customer->fresh()->phone)->toBe('0591000001')
        ->and($customer->fresh()->address)->toBe('الخليل')
        ->and($component->get('deliveryCustomerName'))->toBe('اسم للمستلم بالفاتورة');
});

test('customers suppliers and mixed accounts can all be selected as delivery companies', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'عميل التوصيل', 'type' => 'customer', 'is_active' => true]);
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد التوصيل', 'type' => 'supplier', 'is_active' => true]);
    $both = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'حساب مشترك', 'type' => 'both', 'is_active' => true]);

    $component = Livewire::test('pages::tenant.pos.sales')->call('openDeliveryDetails');
    $options = $component->get('deliveryCompanies')->pluck('id')->all();

    expect($options)->toBe([]);

    $component->set('deliveryCompanySearch', 'عميل')
        ->assertSee('x-on:keydown.arrow-down.prevent', false)
        ->assertSee('x-on:keydown.arrow-up.prevent', false)
        ->assertSee('x-on:keydown.enter.prevent', false);
    expect($component->get('deliveryCompanies')->pluck('id')->all())->toBe([$customer->id]);
    $component->call('selectDeliveryCompany', $customer->id)
        ->assertSet('deliveryCompanyId', $customer->id)
        ->assertSet('deliveryCompanySearch', 'عميل التوصيل')
        ->assertSee('value="عميل التوصيل"', false)
        ->assertSee('bg-blue-50', false);

    $component->set('deliveryCustomerName', 'مستلم')
        ->set('deliveryCustomerPhone', '0590000000')
        ->set('deliveryCustomerAddress', 'الخليل')
        ->set('deliveryCompanyId', $customer->id)
        ->call('confirmDeliveryDetails')
        ->assertHasNoErrors('deliveryCompanyId')
        ->assertSet('deliveryCompanyId', $customer->id);
});

test('voucher print and download include the signed account balance', function (string $type, float $balance): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'الحساب', 'type' => $type === 'receipt' ? 'customer' : 'supplier', 'current_balance' => $balance]);
    $voucher = Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $party->id, 'created_by' => $this->user->id, 'type' => $type, 'amount' => 25, 'payment_method' => 'cash', 'payment_date' => now(), 'voucher_number' => 'V-123']);
    Livewire::test('pages::tenant.accounting.'.($type === 'receipt' ? 'receipts' : 'payment'))
        ->call('print', $voucher->id)->assertSee('رصيد الحساب الحالي بعد السند')->assertSee(number_format($balance, 2))->assertSee('تنزيل PDF');
    $html = view('pages.tenant.accounting.partials.voucher-pdf', ['voucher' => $voucher->load(['party', 'creator', 'branch', 'tenant'])])->render();
    expect($html)->toContain(number_format($balance, 2), 'V-123');
    $response = $this->get(route('tenant.vouchers.pdf', ['type' => $type, 'paymentId' => $voucher->id]));
    $response->assertSuccessful()->assertDownload('voucher-'.$type.'-'.$voucher->id.'.pdf');
    expect($response->streamedContent())->toStartWith('%PDF-');
})->with(['receipt', 'payment'])->with([125.0, 0.0, -50.0]);

test('voucher downloads reject foreign records and wrong voucher types', function (): void {
    $other = Tenant::create(['name' => 'آخر']);
    $voucher = Payment::create(['tenant_id' => $other->id, 'type' => 'receipt', 'amount' => 10, 'payment_date' => now(), 'payment_method' => 'cash', 'voucher_number' => 'FOREIGN']);
    $this->get(route('tenant.vouchers.pdf', ['type' => 'receipt', 'paymentId' => $voucher->id]))->assertNotFound();
    $voucher->update(['tenant_id' => $this->tenant->id]);
    $this->get(route('tenant.vouchers.pdf', ['type' => 'payment', 'paymentId' => $voucher->id]))->assertNotFound();
    $this->user->update(['is_owner' => false]);
    $this->get(route('tenant.vouchers.pdf', ['type' => 'receipt', 'paymentId' => $voucher->id]))->assertForbidden();
});

test('sales invoice summary and pos receipt show account balance', function (): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'الزبون', 'type' => 'customer', 'current_balance' => -35]);
    Livewire::test('pages::tenant.sales.invoices')->set('selectedCustomerId', $party->id)
        ->assertViewHas('accountBalanceAfterInvoice', -35.0)->assertSee('الرصيد بعد الفاتورة');
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $party->id, 'invoice_number' => 'POS-BALANCE', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
    Livewire::test('pages::tenant.pos.sales')->set('currentInvoiceId', $order->id)->call('printReceipt')
        ->assertSet('receipt.account_balance', '-35.00')->assertSee('رصيد الحساب الحالي بعد الفاتورة');
});

test('editing an offer reloads the selected branch price and rejects invalid prices', function (string $page): void {
    $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'الصنف', 'cost_price' => 5]);
    $otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الثاني']);
    foreach ([[$this->branch, 20], [$otherBranch, 30]] as [$branch, $price]) {
        BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'retail_price' => $price, 'stock_quantity' => 7]);
    }
    $offer = ProductOffer::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'offer_quantity' => 3, 'offer_price' => 45, 'start_at' => now(), 'end_at' => null, 'is_active' => true]);
    $component = Livewire::test('pages::tenant.'.$page.'.index')->call($page === 'products' ? 'editOffer' : 'edit', $offer->id)
        ->assertSet('offerRetailPrice', '20')->set($page === 'products' ? 'offerBranchId' : 'branchId', $otherBranch->id)
        ->assertSet('offerRetailPrice', '30')->set('updateOfferRetailPrice', true)->set('offerRetailPrice', '-1')
        ->call($page === 'products' ? 'saveOffer' : 'save')->assertHasErrors(['offerRetailPrice']);
    expect((float) $offer->fresh()->offer_price)->toBe(45.0);
    $component->set('offerRetailPrice', '33')->call($page === 'products' ? 'saveOffer' : 'save')->assertHasNoErrors();
    expect((float) BranchProduct::where('branch_id', $otherBranch->id)->value('retail_price'))->toBe(33.0)
        ->and((float) BranchProduct::where('branch_id', $this->branch->id)->value('retail_price'))->toBe(20.0);
})->with(['products', 'offers']);

test('invoice pdf downloads receive the current signed account balance', function (string $kind, bool $fromPage): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'الحساب', 'type' => 'both', 'current_balance' => -123.45]);
    if ($kind === 'sales') {
        $invoice = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $party->id, 'invoice_number' => 'PDF-BAL', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
        $url = route('tenant.sale-invoices.pdf', ['orderId' => $invoice->id]);
    } else {
        $invoice = Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $party->id, 'reference_number' => 'PDF-BAL', 'total' => 10, 'paid_amount' => 10]);
        $url = route('tenant.purchase-invoices.pdf', ['purchaseId' => $invoice->id]);
    }
    $pdf = Mockery::mock();
    $pdf->shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use ($kind): bool {
        expect((float) $data['accountBalance'])->toBe(-123.45);
        expect(view($view, $data)->render())->toContain($kind === 'sales' ? '123.45' : '-123.45');

        return true;
    });
    $pdf->shouldReceive('output')->once()->andReturn('%PDF-test');
    app()->instance('dompdf.wrapper', $pdf);
    if ($fromPage) {
        Livewire::test('pages::tenant.'.($kind === 'sales' ? 'sales' : 'purchases').'.invoices')
            ->set($kind === 'sales' ? 'currentOrderId' : 'currentPurchaseId', $invoice->id)
            ->call('downloadInvoicePdf')->assertFileDownloaded(($kind === 'sales' ? 'sales' : 'purchase').'-invoice-PDF-BAL.pdf');
    } else {
        $response = $this->get($url)->assertSuccessful();
        expect($response->streamedContent())->toBe('%PDF-test');
    }
})->with(['sales', 'purchase'])->with([true, false]);
