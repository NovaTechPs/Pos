<?php

use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('party codes increase numerically per tenant and include deleted parties and suppliers', function (): void {
    $tenant = Tenant::create(['name' => 'Codes']);
    $otherTenant = Tenant::create(['name' => 'Other']);
    $first = Party::create(['tenant_id' => $tenant->id, 'name' => 'First']);
    expect($first->code)->toBe('1');
    $first->delete();
    $supplier = Party::create(['tenant_id' => $tenant->id, 'name' => 'Supplier', 'type' => 'supplier']);
    expect($supplier->code)->toBe('2');
    Party::create(['tenant_id' => $tenant->id, 'name' => 'Imported', 'code' => '10']);
    $next = Party::create(['tenant_id' => $tenant->id, 'name' => 'Next']);
    expect($next->code)->toBe('11');
    $next->update(['name' => 'Renamed']);
    expect($next->fresh()->code)->toBe('11')
        ->and(Party::create(['tenant_id' => $otherTenant->id, 'name' => 'Other'])->code)->toBe('1');
});

test('backfill assigns missing codes in id order without changing existing codes', function (): void {
    $tenant = Tenant::create(['name' => 'Legacy']);
    DB::table('parties')->insert([
        ['tenant_id' => $tenant->id, 'name' => 'Existing', 'code' => '10', 'deleted_at' => null],
        ['tenant_id' => $tenant->id, 'name' => 'Missing', 'code' => null, 'deleted_at' => null],
        ['tenant_id' => $tenant->id, 'name' => 'Deleted', 'code' => '', 'deleted_at' => now()],
    ]);
    $migration = require database_path('migrations/2026_10_26_213940_backfill_party_codes.php');
    $migration->up();
    $migration->up();
    expect(Party::withTrashed()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('code')->all())
        ->toBe(['10', '11', '12']);
});

test('customers can be found by code in the livewire listing', function (): void {
    $tenant = Tenant::create(['name' => 'Search']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $customer = Party::create(['tenant_id' => $tenant->id, 'name' => 'Matching', 'code' => '12345']);
    Party::create(['tenant_id' => $tenant->id, 'name' => 'Excluded']);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    Livewire::test('pages::tenant.customers.index')
        ->assertSee('رمز العميل')
        ->assertSeeHtml('data-party-code="12345"')
        ->set('search', '12345')
        ->assertViewHas('parties', fn ($parties): bool => $parties->modelKeys() === [$customer->id])
        ->assertSee('12345');
});

test('party code generation preserves legacy text codes and ignores them in numeric sequences', function (): void {
    $tenant = Tenant::create(['name' => 'Mixed codes']);
    DB::table('parties')->insert([
        ['tenant_id' => $tenant->id, 'name' => 'Legacy customer', 'code' => 'C00000001', 'deleted_at' => null],
        ['tenant_id' => $tenant->id, 'name' => 'Legacy supplier', 'code' => 'S99999999', 'deleted_at' => null],
        ['tenant_id' => $tenant->id, 'name' => 'Numeric', 'code' => '00009', 'deleted_at' => now()],
        ['tenant_id' => $tenant->id, 'name' => 'Other text', 'code' => '1e12', 'deleted_at' => null],
    ]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect(Party::create(['tenant_id' => $tenant->id, 'name' => 'New customer'])->code)->toBe('10')
        ->and(DB::table('parties')->where('name', 'Legacy customer')->value('code'))->toBe('C00000001')
        ->and(collect($queries)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'cast(')))->toBeFalse();
});

test('backfill supports prefixed codes and numeric codes larger than a PHP integer', function (): void {
    $tenant = Tenant::create(['name' => 'Legacy mixed codes']);
    DB::table('parties')->insert([
        ['tenant_id' => $tenant->id, 'name' => 'Prefixed', 'code' => 'C00000001'],
        ['tenant_id' => $tenant->id, 'name' => 'Large numeric', 'code' => '18446744073709551615'],
        ['tenant_id' => $tenant->id, 'name' => 'Missing one', 'code' => null],
        ['tenant_id' => $tenant->id, 'name' => 'Missing two', 'code' => ''],
    ]);
    $migration = require database_path('migrations/2026_10_26_213940_backfill_party_codes.php');
    $migration->up();
    $migration->up();

    expect(DB::table('parties')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('code')->all())
        ->toBe(['C00000001', '18446744073709551615', '18446744073709551616', '18446744073709551617'])
        ->and(Party::create(['tenant_id' => $tenant->id, 'name' => 'Next'])->code)->toBe('18446744073709551618');
});
