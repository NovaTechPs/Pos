<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\MainDataSeeder;
use Illuminate\Support\Facades\Hash;

test('main seeder provisions configured accounts without catalog or sales data', function () {
    config(['client-setup.password' => 'Main-Setup-Password42!']);
    $this->seed(MainDataSeeder::class);
    expect(User::count())->toBe(5)->and(Branch::count())->toBe(3)->and(Role::count())->toBe(3)
        ->and(Tenant::count())->toBe(1)->and(Permission::count())->toBeGreaterThan(0)
        ->and(Product::count())->toBe(0)->and(Order::count())->toBe(0);
    $khaled = User::where('email', 'kalide@craze.com')->sole();
    expect($khaled->branch->name)->toBe('craze')
        ->and(Hash::check('Main-Setup-Password42!', $khaled->password))->toBeTrue();
});

test('main seeder reruns preserve passwords and custom role permissions', function () {
    config(['client-setup.password' => 'Main-Setup-Password42!']);
    $this->seed(MainDataSeeder::class);
    $owner = User::where('email', 'user@example.com')->sole();
    $owner->update(['password' => 'Changed-Password42!']);
    Role::where('name', 'admin')->sole()->permissions()->sync([]);
    config(['client-setup.password' => 'Different-Password42!']);
    $this->seed(MainDataSeeder::class);
    expect(User::count())->toBe(5)->and(Branch::count())->toBe(3)
        ->and(Hash::check('Changed-Password42!', $owner->fresh()->password))->toBeTrue()
        ->and(Role::where('name', 'admin')->sole()->permissions()->count())->toBe(0);
});

test('main seeder does not create production accounts without a configured password', function () {
    config(['client-setup.password' => null]);
    $this->seed(MainDataSeeder::class);
    expect(User::count())->toBe(0)->and(Tenant::count())->toBe(0)->and(Permission::count())->toBe(0);
});

test('main seeder does not bootstrap over an existing or deleted account', function () {
    $existing = User::factory()->create(['email' => 'existing@example.com']);
    $existing->delete();
    config(['client-setup.password' => 'Main-Setup-Password42!']);
    $this->seed(MainDataSeeder::class);
    expect(User::withTrashed()->count())->toBe(1)->and(Tenant::count())->toBe(0);
});

test('docker starts main seeding after migrations and never clears the database', function () {
    $docker = file_get_contents(base_path('Dockerfile'));
    expect($docker)->toContain('php artisan db:seed --class=MainDataSeeder --force --no-interaction')
        ->not->toContain('migrate:fresh')->not->toContain('wasla:restore-fresh');
    expect(strpos($docker, 'php artisan migrate'))->toBeLessThan(strpos($docker, 'php artisan db:seed'));
});
