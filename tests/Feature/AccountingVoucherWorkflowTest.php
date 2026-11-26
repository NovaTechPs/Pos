<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Voucher tenant']);
    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_owner' => true,
    ]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
});

test('a receipt opened from a voucher link can be deleted after unlocking its form', function (string $partyType): void {
    $party = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Receipt account',
        'type' => $partyType,
        'is_active' => true,
        'current_balance' => 100,
    ]);

    Livewire::test('pages::tenant.accounting.receipts')
        ->call('openCreate')
        ->assertDontSeeHtml('data-voucher-delete')
        ->call('selectParty', $party->id)
        ->set('amount', '25')
        ->call('saveReceipt')
        ->assertHasNoErrors();

    $receipt = Payment::query()->sole();
    $balanceBeforeDeletion = (float) $party->fresh()->current_balance;

    $component = Livewire::withQueryParams(['voucher' => $receipt->id])
        ->test('pages::tenant.accounting.receipts')
        ->assertSet('showForm', true)
        ->assertSet('voucherLocked', true)
        ->assertDontSeeHtml('data-voucher-delete')
        ->call('unlockVoucher')
        ->assertSeeHtml('data-voucher-delete')
        ->call('confirmDelete', $receipt->id)
        ->assertSet('showDeleteModal', true)
        ->set('showDeleteModal', false)
        ->assertSet('showForm', true);

    expect($receipt->fresh()->trashed())->toBeFalse()
        ->and((float) $party->fresh()->current_balance)->toBe($balanceBeforeDeletion);

    $component->call('confirmDelete', $receipt->id)
        ->call('delete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('showForm', false)
        ->assertSet('paymentId', null)
        ->assertSet('deleteId', null)
        ->assertDispatched('toast');

    expect(Payment::withTrashed()->findOrFail($receipt->id)->trashed())->toBeTrue()
        ->and((float) $party->fresh()->current_balance)->toBe(100.0);
})->with(['customer', 'supplier']);

test('voucher amount is a plain numeric field and accepts decimal amounts', function (string $page, string $action, string $type): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'الحساب', 'type' => $type, 'is_active' => true]);
    $component = Livewire::test('pages::tenant.accounting.'.$page)->call('openCreate');
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $input = (new DOMXPath($dom))->query('//input[@id="voucher-amount"]')->item(0);
    expect($input)->not->toBeNull()
        ->and($input->getAttribute('type'))->toBe('number')
        ->and($input->getAttribute('inputmode'))->toBe('decimal')
        ->and($input->hasAttribute('placeholder'))->toBeFalse()
        ->and($input->getAttribute('class'))->toContain('[appearance:textfield]', '[&::-webkit-inner-spin-button]:appearance-none')
        ->and($input->parentNode->getElementsByTagName('span')->length)->toBe(0);
    $component->set('partyId', $party->id)->set('amount', '11.64')
        ->call($action)->assertHasNoErrors();
    expect((float) Payment::query()->sole()->amount)->toBe(11.64);
})->with([
    ['receipts', 'saveReceipt', 'customer'],
    ['payment', 'savePayment', 'supplier'],
]);

test('voucher pages use the financial voucher workspace and centered creation form', function (string $page, string $title): void {
    $page = Livewire::test('pages::tenant.accounting.'.$page)
        ->assertSeeHtml('data-voucher-workspace')
        ->assertSee('فلاتر متقدمة')
        ->assertSee($title)
        ->call('openCreate')
        ->assertSeeHtml('data-voucher-form')
        ->assertSeeHtml('data-voucher-mode')
        ->assertDontSeeHtml('h-14 w-full')
        ->assertSeeHtml('value="bank_transfer"')
        ->assertSeeHtml('value="cheque"');

    expect($page->html())->toContain('max-w-3xl')->toContain('data-voucher-compact')->toContain('إنشاء '.$title);
})->with([
    ['receipts', 'سند قبض'],
    ['payment', 'سند صرف'],
]);

test('saving a payment voucher closes the form and dispatches one success notice', function (): void {
    $supplier = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Voucher supplier',
        'type' => 'supplier',
        'is_active' => true,
    ]);

    Livewire::test('pages::tenant.accounting.payment')
        ->call('openCreate')
        ->call('selectParty', $supplier->id)
        ->set('amount', '40')
        ->call('savePayment')
        ->assertSet('showForm', false)
        ->assertSet('amount', '')
        ->assertDontSee('id="payment-voucher-form"', false)
        ->assertDispatched('toast');

    expect(Payment::query()->where('tenant_id', $this->tenant->id)->where('type', 'payment')->count())->toBe(1);
});

test('saving a receipt voucher closes the form and dispatches one success notice', function (): void {
    $customer = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Voucher customer',
        'type' => 'customer',
        'is_active' => true,
    ]);

    Livewire::test('pages::tenant.accounting.receipts')
        ->call('openCreate')
        ->call('selectParty', $customer->id)
        ->set('amount', '25')
        ->call('saveReceipt')
        ->assertSet('showForm', false)
        ->assertSet('amount', '')
        ->assertDontSee('id="receipt-voucher-form"', false)
        ->assertDispatched('toast');

    expect(Payment::query()->where('tenant_id', $this->tenant->id)->where('type', 'receipt')->count())->toBe(1);
});

test('receipt voucher created from a sales invoice is linked and updates its paid amount', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Voucher branch']);
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Invoice customer', 'type' => 'customer', 'is_active' => true]);
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'invoice_number' => 'SALE-VOUCHER', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 100, 'total' => 100, 'paid_amount' => 0, 'payment_status' => 'unpaid']);

    Livewire::withQueryParams(['order_id' => $order->id, 'amount' => 25])
        ->test('pages::tenant.accounting.receipts')
        ->assertSet('showForm', true)
        ->assertSet('partyId', $customer->id)
        ->set('amount', '25')
        ->call('saveReceipt')
        ->assertHasNoErrors();

    expect((int) Payment::query()->sole()->order_id)->toBe($order->id)
        ->and((float) $order->fresh()->paid_amount)->toBe(25.0)
        ->and($order->fresh()->payment_status)->toBe('partial');
});

test('POS payments stay in the accounting ledger but are not shown as separate receipt vouchers', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'POS receipt branch']);
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'POS customer', 'type' => 'customer', 'is_active' => true]);
    $posOrder = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => null,
        'invoice_number' => 'POS-RECEIPT-LINK',
        'type' => 'pos',
        'source' => 'pos',
        'status' => 'completed',
        'subtotal' => 50,
        'total' => 50,
        'paid_amount' => 50,
        'payment_status' => 'paid',
    ]);
    $posPayment = Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'order_id' => $posOrder->id,
        'type' => 'receipt',
        'voucher_number' => 'POS-RECEIPT-LINK',
        'payment_date' => now(),
        'payment_method' => 'cash',
        'amount' => 50,
    ]);
    $manualReceipt = Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'party_id' => $customer->id,
        'type' => 'receipt',
        'voucher_number' => 'MANUAL-RECEIPT',
        'payment_date' => now(),
        'payment_method' => 'cash',
        'amount' => 20,
    ]);

    $component = Livewire::test('pages::tenant.accounting.receipts');

    expect($component->get('items')->total())->toBe(1)
        ->and((int) $component->get('items')->first()->id)->toBe($manualReceipt->id);

    $component->call('editVoucherNow', $posPayment->id)
        ->assertSet('showForm', true)
        ->assertNotDispatched('toast');

    expect(Payment::query()->whereKey($posPayment->id)->exists())->toBeTrue();
});

test('legacy POS receipt linked to an anonymous invoice can be edited without changing its party', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Legacy POS branch']);
    $customer = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Legacy POS customer',
        'type' => 'customer',
        'is_active' => true,
        'current_balance' => -30,
    ]);
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => null,
        'invoice_number' => 'LEGACY-POS-EDIT',
        'type' => 'pos',
        'status' => 'completed',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 30,
        'payment_status' => 'partial',
    ]);
    $receipt = Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'party_id' => $customer->id,
        'order_id' => $order->id,
        'created_by' => $this->user->id,
        'type' => 'receipt',
        'voucher_number' => 'LEGACY-POS-EDIT-RCV',
        'payment_date' => now(),
        'payment_method' => 'cash',
        'amount' => 30,
    ]);

    Livewire::withQueryParams(['voucher' => $receipt->id])
        ->test('pages::tenant.accounting.receipts')
        ->assertSet('showForm', true)
        // A voucher opened from a link is view-only until «تعديل».
        ->assertSet('voucherLocked', true)
        ->call('saveReceipt')
        ->assertSet('showForm', true)
        ->call('unlockVoucher')
        ->set('amount', '45')
        ->call('saveReceipt')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect((float) $receipt->fresh()->amount)->toBe(45.0)
        ->and((int) $receipt->fresh()->party_id)->toBe($customer->id)
        ->and((float) $customer->fresh()->current_balance)->toBe(-45.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(45.0);
});

test('legacy POS receipt without a party can be corrected by assigning a customer without a not found error', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Unassigned POS branch']);
    $customer = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Recovered POS customer',
        'type' => 'customer',
        'is_active' => true,
        'current_balance' => 0,
    ]);
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => null,
        'invoice_number' => 'UNASSIGNED-POS-EDIT',
        'type' => 'pos',
        'status' => 'completed',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 30,
        'payment_status' => 'partial',
    ]);
    $receipt = Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'party_id' => null,
        'order_id' => $order->id,
        'created_by' => $this->user->id,
        'type' => 'receipt',
        'voucher_number' => 'UNASSIGNED-POS-EDIT-RCV',
        'payment_date' => now(),
        'payment_method' => 'cash',
        'amount' => 30,
    ]);

    Livewire::test('pages::tenant.accounting.receipts')
        ->call('editVoucherNow', $receipt->id)
        ->call('selectParty', $customer->id)
        ->set('amount', '45')
        ->call('saveReceipt')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect((float) $receipt->fresh()->amount)->toBe(45.0)
        ->and((int) $receipt->fresh()->party_id)->toBe($customer->id)
        ->and((float) $customer->fresh()->current_balance)->toBe(-45.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(45.0);
});

test('linked receipt vouchers can be edited and deleted while keeping the invoice paid amount in sync', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Receipt branch']);
    $customer = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Receipt customer',
        'type' => 'customer',
        'is_active' => true,
        'current_balance' => -25,
    ]);
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'invoice_number' => 'RECEIPT-EDIT',
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 25,
        'payment_status' => 'partial',
    ]);
    $receipt = Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'party_id' => $customer->id,
        'order_id' => $order->id,
        'created_by' => $this->user->id,
        'type' => 'receipt',
        'voucher_number' => 'RECEIPT-EDIT-1',
        'payment_date' => '2026-11-06',
        'payment_method' => 'cash',
        'amount' => 25,
    ]);

    $component = Livewire::test('pages::tenant.accounting.receipts')
        ->assertSee('wire:click="editVoucherNow('.$receipt->id.')"', false)
        ->assertSee('wire:click="confirmDelete('.$receipt->id.')"', false)
        ->call('editVoucherNow', $receipt->id)
        ->set('amount', '40')
        ->call('saveReceipt')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect((float) $customer->fresh()->current_balance)->toBe(-40.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(40.0)
        ->and($order->fresh()->payment_status)->toBe('partial')
        ->and((float) $receipt->fresh()->amount)->toBe(40.0);

    $component->call('confirmDelete', $receipt->id)->call('delete');

    expect((float) $customer->fresh()->current_balance)->toBe(0.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(0.0)
        ->and($order->fresh()->payment_status)->toBe('unpaid')
        ->and(Payment::withTrashed()->findOrFail($receipt->id)->trashed())->toBeTrue();
});

test('editing a linked receipt with another customer shows a validation error instead of a not found page', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Receipt branch']);
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Invoice customer', 'type' => 'customer', 'is_active' => true]);
    $otherCustomer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other customer', 'type' => 'customer', 'is_active' => true]);
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'invoice_number' => 'SALE-LOCKED-CUSTOMER',
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 25,
        'payment_status' => 'partial',
    ]);
    $receipt = Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'party_id' => $customer->id,
        'order_id' => $order->id,
        'created_by' => $this->user->id,
        'type' => 'receipt',
        'voucher_number' => 'RECEIPT-LOCKED-CUSTOMER',
        'payment_date' => '2026-11-08',
        'payment_method' => 'cash',
        'amount' => 25,
    ]);

    Livewire::test('pages::tenant.accounting.receipts')
        ->call('editVoucherNow', $receipt->id)
        ->call('selectParty', $otherCustomer->id)
        ->set('amount', '40')
        ->call('saveReceipt')
        ->assertHasErrors(['partyId'])
        ->assertSet('showForm', true);

    expect((float) $receipt->fresh()->amount)->toBe(25.0)
        ->and((int) $receipt->fresh()->party_id)->toBe($customer->id)
        ->and((float) $order->fresh()->paid_amount)->toBe(25.0);
});

test('payment voucher created from a purchase invoice is linked and updates its paid amount', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Voucher branch']);
    $supplier = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Invoice supplier', 'type' => 'supplier', 'is_active' => true]);
    $purchase = Purchase::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'reference_number' => 'PUR-VOUCHER', 'total' => 100, 'paid_amount' => 0, 'payment_status' => 'unpaid']);

    Livewire::withQueryParams(['purchase_id' => $purchase->id, 'amount' => 40])
        ->test('pages::tenant.accounting.payment')
        ->assertSet('showForm', true)
        ->assertSet('partyId', $supplier->id)
        ->set('amount', '40')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect((int) Payment::query()->sole()->purchase_id)->toBe($purchase->id)
        ->and((float) $purchase->fresh()->paid_amount)->toBe(40.0)
        ->and($purchase->fresh()->payment_status)->toBe('partial');
});

test('linked receipt above invoice total and customer balance remains a full receipt voucher', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Overpayment branch']);
    $customer = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Overpayment customer',
        'type' => 'customer',
        'is_active' => true,
        'current_balance' => 25,
    ]);
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'invoice_number' => 'SALE-OVERPAY',
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 0,
        'payment_status' => 'unpaid',
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test('pages::tenant.accounting.receipts')
        ->set('amount', '150')
        ->call('saveReceipt')
        ->assertHasNoErrors();

    $receipt = Payment::query()->sole();
    expect((int) $receipt->order_id)->toBe($order->id)
        ->and((float) $receipt->amount)->toBe(150.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(100.0)
        ->and($order->fresh()->payment_status)->toBe('paid')
        ->and((float) $customer->fresh()->current_balance)->toBe(-125.0);
});

test('linked supplier payment above invoice total and balance remains a full payment voucher', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Overpayment branch']);
    $supplier = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Overpayment supplier',
        'type' => 'supplier',
        'is_active' => true,
        'current_balance' => 25,
    ]);
    $purchase = Purchase::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'reference_number' => 'PUR-OVERPAY',
        'total' => 100,
        'paid_amount' => 0,
        'payment_status' => 'unpaid',
    ]);

    Livewire::withQueryParams(['purchase_id' => $purchase->id])
        ->test('pages::tenant.accounting.payment')
        ->set('amount', '150')
        ->call('savePayment')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();
    expect((int) $payment->purchase_id)->toBe($purchase->id)
        ->and((float) $payment->amount)->toBe(150.0)
        ->and((float) $purchase->fresh()->paid_amount)->toBe(100.0)
        ->and($purchase->fresh()->payment_status)->toBe('paid')
        ->and((float) $supplier->fresh()->current_balance)->toBe(-125.0);
});

test('vouchers can be previewed for printing with their account, without the creator', function (string $page, string $type): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'Print account', 'type' => 'both', 'is_active' => true]);
    $voucher = Payment::create([
        'tenant_id' => $this->tenant->id,
        'party_id' => $party->id,
        'created_by' => $this->user->id,
        'type' => $type,
        'voucher_number' => 'PRINT-123',
        'payment_date' => '2026-11-06',
        'payment_method' => 'bank_transfer',
        'amount' => 11.64,
        'notes' => 'Print voucher note',
    ]);

    Livewire::test('pages::tenant.accounting.'.$page)
        ->call('print', $voucher->id)
        ->assertSet('showPrintModal', true)
        ->assertSee('PRINT-123')
        ->assertSee('Print account')
        ->assertDontSee($this->user->name)
        ->assertSee('11.64')
        ->assertSee('تحويل بنكي')
        ->assertSee('Print voucher note')
        ->assertSee('voucher-print-modal', false)
        ->assertSee('window.print()', false)
        ->call('closePrint')
        ->assertSet('showPrintModal', false)
        ->assertSet('printVoucher', null);
})->with([['receipts', 'receipt'], ['payment', 'payment']]);

test('voucher printing rejects other tenants and voucher types', function (string $page, string $type, bool $otherTenant): void {
    $tenantId = $otherTenant ? Tenant::create(['name' => 'Other voucher tenant'])->id : $this->tenant->id;
    $voucher = Payment::create([
        'tenant_id' => $tenantId,
        'created_by' => $this->user->id,
        'type' => $otherTenant ? $type : ($type === 'receipt' ? 'payment' : 'receipt'),
        'voucher_number' => 'PRIVATE-123',
        'payment_date' => '2026-11-06',
        'payment_method' => 'cash',
        'amount' => 10,
    ]);

    Livewire::test('pages::tenant.accounting.'.$page)
        ->call('print', $voucher->id)
        ->assertNotFound();
})->with([
    ['receipts', 'receipt', true],
    ['payment', 'payment', true],
    ['receipts', 'receipt', false],
    ['payment', 'payment', false],
]);

test('a new voucher shows the party balance before and after it only once', function (string $page, string $type): void {
    $party = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'أيمن السدر', 'type' => $type, 'current_balance' => 5723]);

    $html = Livewire::test('pages::tenant.accounting.'.$page)
        ->call('openCreate')
        ->call('selectParty', $party->id)
        ->set('amount', 500)
        ->html();

    expect($html)->not->toContain('data-voucher-balance-preview', 'ملخص الرصيد', 'بعد العملية')
        ->and(substr_count($html, '5,723.00'))->toBe(1)
        ->and(substr_count($html, '5,223.00'))->toBe(1);
})->with([['receipts', 'customer'], ['payment', 'supplier']]);

test('a saved voucher opened from the archive is edited after pressing «تعديل»', function (string $page, string $save, string $type, string $voucherType): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Edit branch']);
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'طرف السند', 'type' => $type, 'is_active' => true, 'current_balance' => 0]);
    $voucher = Payment::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'party_id' => $party->id, 'created_by' => $this->user->id,
        'type' => $voucherType, 'voucher_number' => 'EDIT-'.$voucherType, 'payment_date' => now(), 'payment_method' => 'cash', 'amount' => 20,
    ]);
    $before = (float) $party->fresh()->current_balance;

    foreach (['link', 'previous', 'search'] as $way) {
        $component = $way === 'link'
            ? Livewire::withQueryParams(['voucher' => $voucher->id])->test('pages::tenant.accounting.'.$page)
            : Livewire::test('pages::tenant.accounting.'.$page);
        if ($way === 'previous') {
            $component->call('previousVoucher');
        } elseif ($way === 'search') {
            $component->set('voucherArchiveSearch', $voucher->voucher_number)->call('searchVoucherArchive');
        }

        $component->assertSet('paymentId', $voucher->id)->assertSet('voucherLocked', true)
            ->assertSeeHtml('data-voucher-compact')
            ->assertSeeHtml('max-w-3xl')
            ->assertDontSeeHtml('data-voucher-mode')
            ->assertDontSeeHtml('data-voucher-balance-preview')
            ->assertSeeHtml('rows="1"')
            ->assertSeeHtml('data-voucher-unlock')->assertSeeHtml('data-voucher-locked')
            ->call('unlockVoucher')->assertSet('voucherLocked', false)
            ->assertDontSeeHtml('data-voucher-locked')
            ->set('amount', '35')->call($save)->assertHasNoErrors();

        expect((float) $voucher->fresh()->amount)->toBe(35.0);
        $voucher->update(['amount' => 20]);
        $party->update(['current_balance' => $before]);
    }
})->with([
    ['receipts', 'saveReceipt', 'customer', 'receipt'],
    ['payment', 'savePayment', 'supplier', 'payment'],
]);

test('voucher pages open on a new voucher and the inquiry finds old ones by number or account', function (string $page, string $save, string $type): void {
    $single = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'حساب وحيد', 'phone' => '0599111222', 'type' => $type, 'is_active' => true]);
    $repeated = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'حساب متكرر', 'type' => $type, 'is_active' => true]);
    foreach ([$single, $repeated, $repeated] as $party) {
        Livewire::test('pages::tenant.accounting.'.$page)->call('selectParty', $party->id)->set('amount', '10')->call($save)->assertHasNoErrors();
    }
    $singleVoucher = Payment::query()->where('party_id', $single->id)->sole();

    $component = Livewire::test('pages::tenant.accounting.'.$page)
        ->assertSet('showForm', true)
        ->assertSet('paymentId', null)
        ->assertSeeHtml('data-voucher-form')
        ->assertSeeHtml('data-voucher-archive-search');
    expect($component->get('voucherNumber'))->not->toBe('');

    $component->set('voucherArchiveSearch', '0599111')->call('searchVoucherArchive')
        ->assertSet('paymentId', $singleVoucher->id)->assertSet('showForm', true);

    $component->call('openCreate')->set('voucherArchiveSearch', 'متكرر')->call('searchVoucherArchive')
        ->assertSet('showForm', false)->assertSet('tableSearch', 'متكرر');
    expect($component->instance()->items->pluck('party_id')->unique()->all())->toBe([$repeated->id]);

    $component->call('openCreate')->set('voucherArchiveSearch', '')->call('searchVoucherArchive')
        ->assertSet('showForm', false)->assertSet('tableSearch', '');
    expect($component->instance()->items->total())->toBe(3);
})->with([
    ['receipts', 'saveReceipt', 'customer'],
    ['payment', 'savePayment', 'supplier'],
]);

test('a payment voucher can record an expense paid by card without touching any account balance', function (): void {
    $supplier = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'مورد', 'type' => 'supplier', 'is_active' => true, 'current_balance' => 300]);

    $component = Livewire::test('pages::tenant.accounting.payment')
        ->assertSeeHtml('data-payment-kind')
        ->call('setExpenseMode', true)
        ->assertSet('isExpense', true)
        ->assertSeeHtml('data-expense-category')
        ->assertSee('دعاية وإعلان')
        ->set('amount', '150')
        ->set('paymentMethod', 'card')
        ->set('notes', 'إعلان ممول على فيسبوك')
        ->call('savePayment')
        ->assertHasErrors(['expenseCategory' => 'required'])
        ->set('expenseCategory', 'دعاية وإعلان')
        ->call('savePayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success', message: 'تم تسجيل المصروف بنجاح.');

    $expense = Payment::query()->sole();
    expect($expense->party_id)->toBeNull()
        ->and($expense->expense_category)->toBe('دعاية وإعلان')
        ->and($expense->payment_method)->toBe('card')
        ->and((float) $expense->amount)->toBe(150.0)
        ->and($expense->isExpense())->toBeTrue()
        ->and($expense->accountName())->toBe('مصروف: دعاية وإعلان')
        ->and((float) $supplier->fresh()->current_balance)->toBe(300.0);

    // The list shows the expense type, and it can be opened, changed and deleted.
    $component->call('openCreate')->set('showForm', false)->assertSee('مصروف: دعاية وإعلان');
    $component->call('edit', $expense->id)->assertSet('isExpense', true)->assertSet('expenseCategory', 'دعاية وإعلان')
        ->call('unlockVoucher')->set('amount', '175')->call('savePayment')->assertHasNoErrors();
    expect((float) $expense->fresh()->amount)->toBe(175.0)->and((float) $supplier->fresh()->current_balance)->toBe(300.0);

    $component->call('confirmDelete', $expense->id)->call('delete');
    expect($expense->fresh()->trashed())->toBeTrue()->and((float) $supplier->fresh()->current_balance)->toBe(300.0);
});

test('switching a saved supplier payment to an expense gives the supplier its amount back', function (): void {
    $supplier = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'مورد التحويل', 'type' => 'supplier', 'is_active' => true, 'current_balance' => 300]);
    $component = Livewire::test('pages::tenant.accounting.payment')
        ->call('selectParty', $supplier->id)->set('amount', '100')->call('savePayment')->assertHasNoErrors();
    $payment = Payment::query()->sole();
    $afterPayment = (float) $supplier->fresh()->current_balance;
    expect($afterPayment)->not->toBe(300.0);

    $component->call('edit', $payment->id)->call('unlockVoucher')
        ->call('setExpenseMode', true)->set('expenseCategory', 'إيجار')->call('savePayment')->assertHasNoErrors();

    expect($payment->fresh()->party_id)->toBeNull()
        ->and($payment->fresh()->expense_category)->toBe('إيجار')
        ->and((float) $supplier->fresh()->current_balance)->toBe(300.0);

    // A supplier payment still needs its supplier.
    Livewire::test('pages::tenant.accounting.payment')->set('amount', '10')->call('savePayment')->assertHasErrors(['partyId' => 'required']);
});
