<?php

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ClientSetupSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['client-setup.password' => 'Client-Initial-Password42!']);
});

test('client setup creates users and their related tenant branches roles and plan', function (): void {
    $this->seed(ClientSetupSeeder::class);
    $tenant = Tenant::query()->sole();
    $owner = User::query()->where('email', 'user@example.com')->sole();
    $yasmin = User::query()->where('email', 'yasmin@fanos.com')->sole();
    $hadi = User::query()->where('email', 'hade@fanous.com')->sole();
    expect(User::query()->count())->toBe(5)
        ->and(Branch::query()->count())->toBe(3)
        ->and(Role::query()->count())->toBe(3)
        ->and($tenant->owner_id)->toBe($owner->id)
        ->and($owner->isTenantOwner())->toBeTrue()
        ->and($yasmin->role->name)->toBe('admin')
        ->and($yasmin->branch->name)->toBe('فانوس')
        ->and($hadi->role->name)->toBe('wholesale_employee')
        ->and($hadi->branch->name)->toBe('مخزن جملة')
        ->and(Hash::check('Client-Initial-Password42!', $owner->password))->toBeTrue()
        ->and($tenant->hasFeature('pos_system'))->toBeTrue();
    $this->postJson('/api/login', ['email' => $owner->email, 'password' => 'Client-Initial-Password42!'])->assertOk();
});

test('client setup preserves existing user details passwords and role permissions on reruns', function (): void {
    $this->seed(ClientSetupSeeder::class);
    $owner = User::query()->where('email', 'user@example.com')->sole();
    $owner->update(['password' => 'Customer-Changed-Password42!', 'name' => 'Customer name']);
    $role = Role::query()->where('name', 'admin')->sole();
    $role->permissions()->sync([]);
    config(['client-setup.password' => 'Different-Seed-Password42!']);
    $this->seed(ClientSetupSeeder::class);
    expect(User::query()->count())->toBe(5)->and(Tenant::query()->count())->toBe(1)
        ->and(Branch::query()->count())->toBe(3)->and(Role::query()->count())->toBe(3)
        ->and($owner->fresh()->name)->toBe('Customer name')
        ->and(Hash::check('Customer-Changed-Password42!', $owner->fresh()->password))->toBeTrue()
        ->and($role->permissions()->count())->toBe(0);
});

test('client setup uses generated ids without overwriting unrelated records', function (): void {
    $plan = Plan::query()->create(['name' => 'Existing plan', 'slug' => 'existing', 'price' => 99]);
    $tenant = Tenant::query()->create(['name' => 'Existing tenant', 'plan_id' => $plan->id]);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Existing branch']);
    $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'admin']);
    $this->seed(ClientSetupSeeder::class);
    expect($tenant->fresh()->name)->toBe('Existing tenant')
        ->and($branch->fresh()->name)->toBe('Existing branch')
        ->and($role->fresh()->tenant_id)->toBe($tenant->id)
        ->and($plan->fresh()->slug)->toBe('existing')
        ->and(User::query()->where('email', 'user@example.com')->sole()->tenant_id)->not->toBe($tenant->id);
});

test('client setup refuses absent or weak initial passwords before making any changes', function (?string $password): void {
    config(['client-setup.password' => $password]);
    expect(fn () => $this->seed(ClientSetupSeeder::class))->toThrow(ValidationException::class);
    expect(User::query()->count())->toBe(0)->and(Tenant::query()->count())->toBe(0)->and(Permission::query()->count())->toBe(0);
})->with([null, 'password123']);

test('client setup rejects cross tenant email collisions and rolls back all setup data', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Other tenant']);
    $existing = User::factory()->create(['email' => 'yasmin@fanos.com', 'tenant_id' => $tenant->id]);
    expect(fn () => $this->seed(ClientSetupSeeder::class))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(1)->and(Tenant::query()->count())->toBe(1)
        ->and(Plan::query()->count())->toBe(0)->and(Branch::query()->count())->toBe(0)
        ->and($existing->fresh()->tenant_id)->toBe($tenant->id);
});

test('client setup can be explicitly run in production', function (): void {
    app()->instance('env', 'production');
    try {
        $this->artisan('db:seed', ['--class' => ClientSetupSeeder::class, '--force' => true])->assertSuccessful();
        expect(User::query()->count())->toBe(5);
    } finally {
        app()->instance('env', 'testing');
    }
});
