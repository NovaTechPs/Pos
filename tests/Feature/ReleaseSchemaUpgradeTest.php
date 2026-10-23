<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function releaseCompatibilityMigration(): Migration
{
    return require database_path('migrations/2026_10_23_142830_reconcile_existing_installations_for_release.php');
}

test('release migration can run repeatedly on a fresh installation', function (): void {
    releaseCompatibilityMigration()->up();
    releaseCompatibilityMigration()->up();
    expect(Schema::hasIndex('products', ['tenant_id', 'name']))->toBeTrue()
        ->and(Schema::hasIndex('employees', ['user_id'], 'unique'))->toBeTrue();
});

test('release migration restores missing indexes and employee tenant links without losing records', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Upgrade tenant']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Upgrade branch']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $employee = Employee::query()->create(['user_id' => $user->id, 'employee_code' => 'UPGRADE-1', 'first_name' => 'Test', 'last_name' => 'Employee', 'hire_date' => '2026-01-01', 'basic_salary' => 1000, 'salary_type' => 'monthly']);
    Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_tenant_name_index'));
    Schema::table('employees', fn (Blueprint $table) => $table->dropUnique('employees_user_id_unique'));
    releaseCompatibilityMigration()->up();
    expect(Schema::hasIndex('products', ['tenant_id', 'name']))->toBeTrue()
        ->and(Schema::hasIndex('employees', ['user_id'], 'unique'))->toBeTrue()
        ->and($employee->fresh()->tenant_id)->toBe($tenant->id)
        ->and($employee->fresh()->branch_id)->toBe($branch->id)
        ->and($user->tenant->id)->toBe($tenant->id)
        ->and($tenant->users()->sole()->id)->toBe($user->id);
});

test('release migration fills historical shift sync ids once', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Sync tenant']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $id = DB::table('shifts')->insertGetId(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'opening_cash' => 0, 'opened_at' => now(), 'status' => 'open']);
    releaseCompatibilityMigration()->up();
    $uuid = DB::table('shifts')->where('id', $id)->value('sync_uuid');
    releaseCompatibilityMigration()->up();
    expect($uuid)->toBeString()->and(DB::table('shifts')->where('id', $id)->value('sync_uuid'))->toBe($uuid);
});
