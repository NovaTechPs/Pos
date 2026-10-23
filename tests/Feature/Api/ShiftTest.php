<?php

use App\Models\Branch;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can open and close cashier shift', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create([
        'name' => 'Main Branch',
        'tenant_id' => 1,
    ]);

    // 1. Open shift
    $response = $this->actingAs($user)->postJson('/api/shifts/open', [
        'branch_id' => $branch->id,
        'opening_cash' => 500.00,
        'notes' => 'Starting shift',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'shift' => [
                'id',
                'opening_cash',
                'status',
            ],
        ]);

    $shiftId = $response->json('shift.id');

    // 2. Check active shift
    $activeResponse = $this->actingAs($user)->getJson('/api/shifts/active?branch_id='.$branch->id);
    $activeResponse->assertStatus(200)
        ->assertJsonPath('active_shift.id', $shiftId);

    // 3. Close shift
    $closeResponse = $this->actingAs($user)->postJson('/api/shifts/close', [
        'shift_id' => $shiftId,
        'actual_cash' => 750.00,
    ]);

    $closeResponse->assertStatus(200)
        ->assertJsonPath('shift.status', 'closed');
});

test('rejects a forged tenant id in an authenticated api request', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);

    $this->actingAs($user)->getJson('/api/theme?tenant_id=2')->assertNotFound();
});
