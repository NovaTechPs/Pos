<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر الملاحظات', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true, 'is_active' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون الملاحظات', 'type' => 'customer', 'is_active' => true]);
    $this->makeOrder = fn (array $attributes): Order => Order::query()->create($attributes + ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id, 'invoice_number' => 'N-'.str()->random(6), 'type' => 'pos', 'source' => 'pos', 'status' => 'completed',
        'subtotal' => 100, 'total' => 100, 'tax_amount' => 0, 'discount' => 0, 'delivery_fee' => 0, 'paid_amount' => 0, 'payment_status' => 'unpaid']);
});

test('notes open from the toolbar button instead of a box at the bottom', function (): void {
    Livewire::test('pages::tenant.sales.invoices')
        ->assertSeeHtml('data-sales-notes-button')->assertDontSeeHtml('id="sales-invoice-notes"')
        ->call('$set', 'showNotesModal', true)
        ->assertSeeHtml('data-sales-notes-modal')->assertSeeHtml('id="sales-invoice-notes"')
        ->assertSee('ملاحظات الفاتورة')->assertDontSeeHtml('data-sales-delivery-details')
        ->set('notes', 'تسليم بعد العصر')->assertSeeHtml('aria-label="توجد ملاحظة"')
        ->call('$set', 'showNotesModal', false)->assertDontSeeHtml('data-sales-notes-modal');
});

test('a delivery invoice opens its delivery details from the same button', function (): void {
    $company = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'شركة السهم', 'type' => 'supplier', 'is_active' => true]);
    $delivery = ($this->makeOrder)(['total' => 110, 'delivery_fee' => 10, 'delivery_party_id' => $company->id, 'customer_name' => 'نعمة البدوي',
        'customer_phone' => '0505210395', 'customer_address' => 'الداخل جسر الزرقاء', 'notes' => 'ملاحظة التوصيل: الباب الخلفي']);
    $plain = ($this->makeOrder)(['notes' => 'ملاحظة عادية']);

    Livewire::test('pages::tenant.sales.invoices')->call('openInvoiceFromSearch', $delivery->id)
        ->assertSee('التوصيل')
        ->call('$set', 'showNotesModal', true)
        ->assertSee(['بيانات التوصيل', 'نعمة البدوي', '0505210395', 'الداخل جسر الزرقاء', 'شركة السهم', '10.00'])
        ->assertSet('notes', 'ملاحظة التوصيل: الباب الخلفي')
        ->call('openInvoiceFromSearch', $plain->id)
        ->assertSet('showNotesModal', false)
        ->call('$set', 'showNotesModal', true)
        ->assertSee('ملاحظات الفاتورة')->assertDontSeeHtml('data-sales-delivery-details')
        ->assertSet('notes', 'ملاحظة عادية');
});
