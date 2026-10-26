<?php

use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can list and create customers', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);

    Party::create([
        'tenant_id' => 1,
        'name' => 'شركة التقنية المتقدمة',
        'phone' => '0501234567',
        'type' => 'customer',
    ]);

    // 1. List customers
    $response = $this->actingAs($user)->getJson('/api/customers');
    $response->assertStatus(200)
        ->assertJsonStructure([
            'customers' => [
                '*' => ['id', 'code', 'name', 'phone'],
            ],
        ]);

    // 2. Create customer
    $createResponse = $this->actingAs($user)->postJson('/api/customers', [
        'name' => 'محمد أحمد',
        'phone' => '0559876543',
        'email' => 'mohamed@example.com',
    ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('customer.name', 'محمد أحمد')
        ->assertJsonPath('customer.code', '2');
});

test('can create and list suppliers without exposing another tenant', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    Tenant::create(['id' => 2, 'name' => 'مؤسسة ثانية', 'is_active' => true]);
    $supplier = Party::create(['tenant_id' => 1, 'name' => 'المورد المحلي', 'type' => 'supplier']);
    Party::create(['tenant_id' => 2, 'name' => 'مورد خارجي', 'type' => 'supplier']);

    $this->actingAs($user)->getJson('/api/suppliers')
        ->assertOk()->assertJsonPath('suppliers.0.id', $supplier->id)
        ->assertJsonMissing(['name' => 'مورد خارجي']);

    $this->actingAs($user)->postJson('/api/suppliers', ['name' => 'مورد جديد'])
        ->assertCreated()->assertJsonPath('supplier.type', 'supplier');
});
