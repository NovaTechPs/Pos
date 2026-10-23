<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can fetch employee profile and clock attendance', function () {
    $accountBranch = Branch::create(['tenant_id' => 1, 'name' => 'Account branch']);
    $employeeBranch = Branch::create(['tenant_id' => 1, 'name' => 'Work branch']);
    $user = User::factory()->create([
        'tenant_id' => 1,
        'branch_id' => $accountBranch->id,
        'type' => 'tenant_user',
        'is_owner' => false,
        'is_active' => true,
    ]);
    $employee = Employee::create([
        'user_id' => $user->id,
        'tenant_id' => 1,
        'branch_id' => $employeeBranch->id,
        'employee_code' => 'EMP-001',
        'first_name' => 'محمد',
        'last_name' => 'علي',
        'hire_date' => now(),
    ]);

    // 1. Get profile
    $response = $this->actingAs($user)->getJson('/api/employee/profile');
    $response->assertStatus(200)
        ->assertJsonPath('employee.id', $employee->id);

    // 2. Clock in
    $clockResponse = $this->actingAs($user)->postJson('/api/employee/attendance/clock', [
        'type' => 'check_in',
    ]);

    $clockResponse->assertStatus(200)
        ->assertJsonPath('attendance.status', 'present');

    $this->actingAs($user)->postJson('/api/employee/attendance/clock', [
        'employee_id' => $employee->id,
        'type' => 'check_in',
    ])->assertStatus(409);
});
