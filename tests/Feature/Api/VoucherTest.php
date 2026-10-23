<?php

use App\Models\Branch;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can create receipt voucher', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Main', 'tenant_id' => 1]);
    $customer = Party::create(['name' => 'عميل تجريبي', 'tenant_id' => 1, 'type' => 'customer']);

    $response = $this->actingAs($user)->postJson('/api/vouchers/receipts', [
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'amount' => 300.00,
        'notes' => 'دفعة مقدمة من الحساب',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('voucher.amount', '300.00')
        ->assertJsonPath('voucher.type', 'receipt');
});

test('can issue a supplier payment voucher and see it in the tenant voucher list', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Main', 'tenant_id' => 1]);
    $supplier = Party::create(['name' => 'مورد', 'tenant_id' => 1, 'type' => 'supplier', 'current_balance' => 500]);

    $this->actingAs($user)->postJson('/api/vouchers/payments', [
        'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'amount' => 100,
        'payment_method' => 'cash',
    ])->assertCreated()->assertJsonPath('voucher.type', 'payment');

    $this->assertDatabaseHas('parties', ['id' => $supplier->id, 'current_balance' => 400]);
    $this->actingAs($user)->getJson('/api/vouchers?type=payment&branch_id='.$branch->id)
        ->assertOk()->assertJsonPath('vouchers.data.0.party_id', $supplier->id);
});
