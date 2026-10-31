<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('database seeder can be rerun without duplicating generated roles', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Role::query()->where('tenant_id', 1)->where('name', 'wholesale_employee')->count())->toBe(1)
        ->and(Role::query()->where('tenant_id', 1)->where('name', 'admin')->count())->toBe(1)
        ->and(Role::query()->where('tenant_id', 1)->where('name', 'cashier')->count())->toBe(1);
});

test('default local seeding keeps unrelated tenants and creates a usable demo plan', function (): void {
    $existing = Tenant::query()->create(['name' => 'Existing business']);
    $this->seed(DatabaseSeeder::class);
    $owner = User::query()->where('email', 'user@example.com')->sole();
    expect($existing->fresh()->name)->toBe('Existing business')
        ->and($owner->tenant_id)->not->toBe($existing->id)
        ->and($owner->tenant->owner_id)->toBe($owner->id)
        ->and($owner->tenant->hasFeature('pos_system'))->toBeTrue();
});

test('demo data cannot be explicitly seeded in production', function (): void {
    app()->instance('env', 'production');
    try {
        expect(fn () => (new DemoDataSeeder)->run())->toThrow(RuntimeException::class);
        expect(Tenant::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
});

test('demo seeding restores configuration after setup', function (): void {
    config(['client-setup.password' => null]);
    $this->seed(DatabaseSeeder::class);
    expect(config('client-setup.password'))->toBeNull();
});
