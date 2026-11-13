<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HomeDashboard;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
    $this->tenant = Tenant::query()->create(['name' => 'Dashboard shop', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الرئيسي']);
    $this->other = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'فرع الشمال']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true, 'branch_id' => null, 'name' => 'المدير']);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'قهوة', 'cost_price' => 5]);
    $this->sale = function (array $attributes): Order {
        $order = Order::query()->create($attributes + [
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'created_by' => $this->owner->id, 'invoice_number' => 'D-'.str()->uuid(),
            'type' => 'pos', 'status' => 'completed', 'subtotal' => 100, 'total' => 100, 'total_cost' => 60, 'tax_amount' => 0, 'discount' => 0, 'delivery_fee' => 0,
            'paid_amount' => 100, 'created_at' => '2026-10-15 10:00:00',
        ]);
        $order->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 1,
            'unit_price' => $order->total, 'total_price' => $order->total, 'cost_price' => $order->total_cost, 'total_cost' => $order->total_cost]);

        return $order;
    };
});

test('the dashboard periods and the comparison period have the same length', function (): void {
    [$from, $to] = HomeDashboard::range('month', Carbon::parse('2026-10-20'));
    expect([$from->toDateString(), $to->toDateString()])->toBe(['2026-10-01', '2026-10-20'])
        ->and(array_map(fn (Carbon $date): string => $date->toDateString(), HomeDashboard::previousRange($from, $to)))->toBe(['2026-09-11', '2026-09-30'])
        ->and(HomeDashboard::range('week', Carbon::parse('2026-10-20'))[0]->toDateString())->toBe('2026-10-14');
});

test('the dashboard figures match the reports and compare with the previous period', function (): void {
    ($this->sale)([]);
    ($this->sale)(['branch_id' => $this->other->id, 'total' => 300, 'subtotal' => 300, 'total_cost' => 200, 'created_at' => '2026-10-18 09:00:00']);
    ($this->sale)(['total' => 200, 'subtotal' => 200, 'total_cost' => 100, 'created_at' => '2026-09-25 09:00:00']);
    Payment::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => 'payment', 'voucher_number' => 'EXP-1',
        'amount' => 40, 'payment_method' => 'cash', 'payment_date' => '2026-10-16 10:00:00']);
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون آجل', 'type' => 'customer', 'opening_balance' => 0, 'created_at' => '2026-10-01']);
    ($this->sale)(['customer_id' => $customer->id, 'type' => 'wholesale', 'paid_amount' => 0, 'created_at' => '2026-10-10 10:00:00']);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 1, 'alert_quantity' => 5]);

    $data = (new HomeDashboard($this->owner))->build('month');

    expect($data['kpis']['revenue'])->toBe(['value' => 500.0, 'change' => 150.0])
        ->and($data['kpis']['gross']['value'])->toBe(180.0)
        ->and($data['kpis']['after_expenses']['value'])->toBe(140.0)
        ->and($data['kpis']['invoices'])->toBe(['value' => 3.0, 'change' => 200.0])
        ->and(collect($data['branches'])->pluck('revenue', 'name')->all())->toBe(['الرئيسي' => 200.0, 'فرع الشمال' => 300.0])
        ->and($data['trend'])->toHaveCount(20)
        ->and(collect($data['trend'])->firstWhere('label', '18/10')['revenue'])->toBe(300.0)
        ->and($data['products'][0]['name'])->toBe('قهوة')
        ->and($data['expenses'])->toHaveKeys(['تكلفة البضاعة المباعة', 'مصروفات تشغيلية'])
        ->and(collect($data['alerts'])->pluck('label')->all())->toContain('أصناف وصلت حد التنبيه أو نفدت');
});

test('managers see the live dashboard on the home page with charts and links to the reports', function (): void {
    ($this->sale)([]);

    $this->get('/dashboard')->assertOk()->assertSee('data-home-dashboard', false);

    Livewire::test('pages::tenant.home-dashboard')
        ->assertSee('data-home-kpi="revenue"', false)
        ->assertSee('data-home-chart="trend"', false)
        ->assertSee('dashboardChart(', false)
        ->assertSee(route('tenant.reports', ['report' => 'profit', 'from' => '2026-10-01', 'to' => '2026-10-20']))
        ->set('period', 'year')
        ->assertSee(route('tenant.reports', ['report' => 'profit', 'from' => '2026-01-01', 'to' => '2026-10-20']))
        ->set('branchId', $this->other->id)
        ->assertSee(route('tenant.reports', ['report' => 'branches', 'from' => '2026-01-01', 'to' => '2026-10-20', 'branch_id' => $this->other->id]));
});

test('employees without report access keep the simple home page', function (): void {
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'مشرف']);
    $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'dashboard.view'], ['display_name' => 'x', 'group' => 'x']));
    $employee = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => false, 'branch_id' => $this->branch->id, 'role_id' => $role->id]);

    $this->actingAs($employee)->get('/dashboard')->assertOk()->assertDontSee('data-home-dashboard', false);
    Livewire::test('pages::tenant.home-dashboard')->assertForbidden();
});
