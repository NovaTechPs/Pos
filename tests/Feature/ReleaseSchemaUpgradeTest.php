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

test('release migration upgrades the legacy employees table with missing tenant and branch columns', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Legacy tenant']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Legacy branch']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    Schema::table('employees', function (Blueprint $table): void {
        $table->dropConstrainedForeignId('tenant_id');
        $table->dropConstrainedForeignId('branch_id');
    });

    $employeeId = DB::table('employees')->insertGetId([
        'user_id' => $user->id,
        'employee_code' => 'LEGACY-EMPLOYEE',
        'first_name' => 'Legacy',
        'last_name' => 'Employee',
        'hire_date' => '2026-01-01',
    ]);

    releaseCompatibilityMigration()->up();
    releaseCompatibilityMigration()->up();

    $employee = Employee::query()->findOrFail($employeeId);
    expect(Schema::hasColumns('employees', ['tenant_id', 'branch_id']))->toBeTrue()
        ->and($employee->tenant_id)->toBe($tenant->id)
        ->and($employee->branch_id)->toBe($branch->id)
        ->and($employee->user_id)->toBe($user->id);
});

test('container applies migrations successfully before starting the web server without seeding demo data', function (): void {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $startup = substr($dockerfile, strpos($dockerfile, 'CMD '));

    expect($startup)->toContain('&& php artisan migrate --force --no-interaction')
        ->not->toContain('--seed');
    expect(strpos($startup, 'php artisan migrate'))->toBeLessThan(strpos($startup, 'php artisan optimize'))
        ->and(strpos($startup, 'php artisan migrate'))->toBeLessThan(strpos($startup, 'php-fpm'));
});

test('release migration selects only the grouped user id when checking duplicate employee accounts', function (): void {
    Schema::table('employees', fn (Blueprint $table) => $table->dropUnique('employees_user_id_unique'));
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        releaseCompatibilityMigration()->up();
        $groupedQueries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'having count(*) > 1'));

        expect($groupedQueries)->toHaveCount(1);
        expect($groupedQueries->first())->toContain('select "user_id"')->not->toContain('select *');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('release migration reports duplicate employee accounts without removing records', function (): void {
    $user = User::factory()->create();
    Schema::table('employees', fn (Blueprint $table) => $table->dropUnique('employees_user_id_unique'));
    foreach (['DUPLICATE-ONE', 'DUPLICATE-TWO'] as $code) {
        DB::table('employees')->insert([
            'user_id' => $user->id,
            'employee_code' => $code,
            'first_name' => 'Duplicate',
            'last_name' => 'Employee',
            'hire_date' => '2026-01-01',
        ]);
    }

    expect(fn () => releaseCompatibilityMigration()->up())
        ->toThrow(RuntimeException::class, 'Resolve duplicate employee user accounts');
    expect(DB::table('employees')->where('user_id', $user->id)->count())->toBe(2);
});
