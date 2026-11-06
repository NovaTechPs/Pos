<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الاختبار']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true, 'type' => 'tenant_user']);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

test('tax visibility can be saved and reloaded without affecting another store', function (bool $visible): void {
    $otherTenant = Tenant::create(['name' => 'متجر آخر']);
    TenantSetting::setBool($otherTenant->id, 'show_tax', ! $visible);

    Livewire::test('pages::settings.store')
        ->assertSet('showTax', true)
        ->set('showTax', $visible)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test('pages::settings.store')->assertSet('showTax', $visible);
    expect(TenantSetting::getBool($this->tenant->id, 'show_tax'))->toBe($visible)
        ->and(TenantSetting::getBool($otherTenant->id, 'show_tax'))->toBe(! $visible);
})->with([true, false]);

test('sales invoice tax fields follow the store preference and remain visible by default', function (?bool $visible): void {
    if ($visible !== null) {
        TenantSetting::setBool($this->tenant->id, 'show_tax', $visible);
    }

    $component = Livewire::test('pages::tenant.sales.invoices');

    if ($visible ?? true) {
        $component->assertSee('قيمة الضريبة المضمنة')->assertSee('wire:model.live.debounce.500ms="taxPercent"', false);
    } else {
        $component->assertDontSee('قيمة الضريبة المضمنة')->assertDontSee('wire:model.live.debounce.500ms="taxPercent"', false)
            ->assertDontSee('الأسعار شاملة الضريبة');
    }
})->with([true, false, null]);

test('purchase invoice tax follows the store preference', function (?bool $visible): void {
    if ($visible !== null) {
        TenantSetting::setBool($this->tenant->id, 'show_tax', $visible);
    }

    $component = Livewire::test('pages::tenant.purchases.invoices');

    if ($visible ?? true) {
        $component->assertSee('data-purchase-tax', false)->assertSee('قيمة الضريبة');
    } else {
        $component->assertDontSee('data-purchase-tax', false)->assertDontSee('قيمة الضريبة');
    }
})->with([true, false, null]);

test('purchase printing follows visibility and uses the saved tax', function (bool $visible): void {
    TenantSetting::setBool($this->tenant->id, 'show_tax', $visible);
    $purchase = Purchase::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'reference_number' => 'TAX-PURCHASE',
        'total' => 116,
        'paid_amount' => 0,
        'tax_amount' => 16,
        'tax_percent' => 16,
        'payment_status' => 'unpaid',
    ]);

    Livewire::test('pages::tenant.purchases.invoices')
        ->set('currentPurchaseId', $purchase->id)
        ->assertViewHas('taxAmount', 16.0)
        ->call('printCurrentInvoice')
        ->assertDispatched('print-purchase-invoice', function (string $event, array $params) use ($visible): bool {
            return $params['invoice']['show_tax'] === $visible
                && (float) $params['invoice']['tax'] === 16.0
                && (float) $params['invoice']['total'] === 116.0;
        });

    expect((float) $purchase->fresh()->tax_amount)->toBe(16.0);
})->with([true, false]);

test('printing follows tax visibility without changing saved invoice amounts', function (bool $visible): void {
    TenantSetting::setBool($this->tenant->id, 'show_tax', $visible);
    $order = Order::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'invoice_number' => 'TAX-TEST',
        'type' => 'pos',
        'status' => 'completed',
        'subtotal' => 116,
        'total' => 116,
        'tax_amount' => 16,
        'tax_percent' => 16,
    ]);

    Livewire::test('pages::tenant.sales.invoices')
        ->set('currentOrderId', $order->id)
        ->call('printCurrentInvoice')
        ->assertDispatched('print-sales-invoice', function (string $event, array $params) use ($visible): bool {
            return $params['invoice']['show_tax'] === $visible
                && (float) $params['invoice']['tax'] === 16.0
                && (float) $params['invoice']['total'] === 116.0;
        });

    expect((float) $order->fresh()->tax_amount)->toBe(16.0);
})->with([true, false]);
