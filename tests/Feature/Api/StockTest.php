<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can create and list stock transfers', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch1 = Branch::create(['name' => 'Main Branch', 'tenant_id' => 1]);
    $branch2 = Branch::create(['name' => 'Secondary Branch', 'tenant_id' => 1]);
    $product = Product::create(['name' => 'Laptop', 'tenant_id' => 1]);

    // 1. Create transfer
    $response = $this->actingAs($user)->postJson('/api/stock/transfers', [
        'from_branch_id' => $branch1->id,
        'to_branch_id' => $branch2->id,
        'notes' => 'Transferring stock',
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 5,
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('transfer.status', 'pending');

    // 2. List transfers
    $listResponse = $this->actingAs($user)->getJson('/api/stock/transfers?branch_id='.$branch1->id);
    $listResponse->assertStatus(200)
        ->assertJsonStructure([
            'transfers' => [
                '*' => ['id', 'transfer_number', 'status'],
            ],
        ]);
});
