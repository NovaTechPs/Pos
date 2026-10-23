<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can fetch financial and sales summary report', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Main Branch', 'tenant_id' => 1]);

    Order::create([
        'tenant_id' => 1,
        'branch_id' => $branch->id,
        'invoice_number' => 'INV-001',
        'type' => 'pos',
        'subtotal' => 100.00,
        'total' => 100.00,
        'status' => 'completed',
    ]);

    $response = $this->actingAs($user)->getJson('/api/reports/summary?branch_id='.$branch->id);

    $response->assertStatus(200)
        ->assertJsonPath('summary.total_sales_count', 1)
        ->assertJsonPath('summary.total_sales_revenue', 100);
});
