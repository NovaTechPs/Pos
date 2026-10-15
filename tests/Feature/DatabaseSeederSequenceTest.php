<?php

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('database seeder can be rerun after creating fixed id roles', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Role::query()->where('tenant_id', 1)->where('name', 'wholesale_employee')->count())->toBe(1)
        ->and(Role::query()->where('tenant_id', 1)->where('name', 'admin')->count())->toBe(1)
        ->and(Role::query()->where('tenant_id', 1)->where('name', 'cashier')->count())->toBe(1);
});
