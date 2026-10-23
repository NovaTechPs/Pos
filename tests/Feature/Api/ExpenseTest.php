<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can create and list expenses', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Main', 'tenant_id' => 1]);

    // 1. Create expense
    $response = $this->actingAs($user)->postJson('/api/expenses', [
        'branch_id' => $branch->id,
        'amount' => 150.00,
        'notes' => 'شراء أدوات نظافة',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('expense.amount', '150.00');

    // 2. List expenses
    $listResponse = $this->actingAs($user)->getJson('/api/expenses?branch_id='.$branch->id);
    $listResponse->assertStatus(200)
        ->assertJsonStructure([
            'expenses' => [
                '*' => ['id', 'amount', 'notes'],
            ],
        ]);
});
