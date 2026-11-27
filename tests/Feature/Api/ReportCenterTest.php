<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
    $this->tenant = Tenant::create(['name' => 'متجر التقارير', 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الرئيسي']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true, 'branch_id' => null]);
    $this->customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون التطبيق', 'code' => 'C-1', 'type' => 'customer', 'opening_balance' => 0]);
    $this->order = fn (array $attributes = []): Order => Order::create($attributes + [
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'APP-'.str()->uuid(),
        'type' => 'pos', 'source' => 'pos', 'status' => 'completed', 'subtotal' => 120, 'total' => 120, 'total_cost' => 70,
        'tax_amount' => 0, 'discount' => 0, 'delivery_fee' => 0, 'paid_amount' => 120, 'created_at' => '2026-10-15 12:00:00',
    ]);
});

test('lists every report the owner may open, grouped like the web report center', function (): void {
    $response = $this->actingAs($this->owner)->getJson('/api/reports')->assertOk();

    expect(collect($response->json('groups'))->pluck('name')->all())->toBe(array_keys(ReportingService::NAVIGATION_GROUPS))
        ->and(collect($response->json('groups'))->flatMap(fn (array $group) => collect($group['reports'])->pluck('key'))->sort()->values()->all())
        ->toBe(collect(array_keys(ReportingService::REPORTS))->sort()->values()->all())
        ->and($response->json('groups.0.reports.0'))->toMatchArray(['key' => 'statement', 'title' => 'كشف حساب زبون / مورد', 'all_branches' => true])
        ->and($response->json('branches.0.name'))->toBe('الرئيسي');
});

test('a report page carries the web columns, formatted cells and totals', function (): void {
    ($this->order)();
    ($this->order)(['total' => 80, 'subtotal' => 80, 'total_cost' => 30, 'paid_amount' => 80]);
    ($this->order)(['created_at' => '2026-09-01 12:00:00']);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/reports/sales?from=2026-10-01&to=2026-10-30&branch_id='.$this->branch->id)
        ->assertOk()
        ->assertJsonPath('title', 'ملخص المبيعات يوم بيوم')
        ->assertJsonPath('branch_name', 'الرئيسي')
        ->assertJsonPath('total', 1)
        ->assertJsonPath('page', 1);

    $definition = (new ReportingService($this->owner, $this->branch->id, false))->prepare('sales', [
        'from' => '2026-10-01', 'to' => '2026-10-30', 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all',
    ]);
    $columns = collect($response->json('columns'));
    $row = array_combine($columns->pluck('key')->all(), $response->json('rows.0'));
    expect($columns->pluck('label')->all())->toBe(array_values($definition['columns']))
        ->and($row['day'])->toBe('2026-10-15')
        ->and(collect($response->json('summary'))->pluck('value', 'label')['صافي المبيعات'])->toBe('200.00');
});

test('the statement needs a customer or supplier, like the web', function (): void {
    $this->actingAs($this->owner)->getJson('/api/reports/statement?from=2026-10-01&to=2026-10-30')
        ->assertUnprocessable()->assertJsonValidationErrors(['party_id' => 'اختر زبوناً أو مورداً أولاً.']);

    ($this->order)(['customer_id' => $this->customer->id, 'paid_amount' => 0]);
    $this->actingAs($this->owner)->getJson('/api/reports/statement?from=2026-10-01&to=2026-10-30&party_id='.$this->customer->id)
        ->assertOk()->assertJsonPath('total', fn (int $total): bool => $total >= 1);
});

test('report filters are validated and unknown reports are not found', function (): void {
    $this->actingAs($this->owner)->getJson('/api/reports/sales?from=2026-10-20&to=2026-10-01')
        ->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->actingAs($this->owner)->getJson('/api/reports/nothing')->assertNotFound();
});

test('a user without the report permission is refused', function (): void {
    $cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => false, 'is_active' => true, 'branch_id' => $this->branch->id]);

    $this->actingAs($cashier)->getJson('/api/reports')->assertForbidden();
    $this->actingAs($cashier)->getJson('/api/reports/sales')->assertForbidden();
});

test('parties and products can be looked up for the report filters', function (): void {
    Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد التطبيق', 'type' => 'supplier', 'opening_balance' => 0]);
    Product::create(['tenant_id' => $this->tenant->id, 'name' => 'كاسات شاي', 'product_number' => 'P-77']);

    $this->actingAs($this->owner)->getJson('/api/reports/parties?search=التطبيق&type=supplier')
        ->assertOk()->assertJsonCount(1, 'parties')->assertJsonPath('parties.0.name', 'مورد التطبيق');
    $this->actingAs($this->owner)->getJson('/api/reports/parties?search=C-1')
        ->assertOk()->assertJsonPath('parties.0.name', 'زبون التطبيق');
    $this->actingAs($this->owner)->getJson('/api/reports/products?search=شاي')
        ->assertOk()->assertJsonPath('products.0.product_number', 'P-77');
});
