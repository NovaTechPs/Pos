<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeePayment;
use App\Models\EmployeePayroll;
use App\Models\Order;
use App\Models\PayrollPeriod;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function schemaEmployee(Tenant $tenant, string $code = 'EMP-001'): Employee
{
    return Employee::query()->create([
        'tenant_id' => $tenant->id, 'employee_code' => $code, 'first_name' => 'موظف',
        'last_name' => 'اختبار', 'hire_date' => '2026-01-01', 'basic_salary' => 1000,
    ]);
}

test('original creation files contain the complete schema without compatibility migrations', function (): void {
    $originalConnection = DB::getDefaultConnection();
    config(['database.connections.original_schema_test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('original_schema_test');

    try {
        $files = glob(database_path('migrations/*.php'));
        sort($files);
        foreach ($files as $file) {
            if (basename($file) < '2026_10_23') {
                (require $file)->up();
            }
        }

        expect(Schema::hasColumns('payroll_periods', ['tenant_id', 'start_date', 'end_date']))->toBeTrue()
            ->and(Schema::hasColumns('employee_payroll_items', ['employee_advance_id', 'employee_payroll_carryover_id']))->toBeTrue()
            ->and(Schema::hasIndex('products', ['tenant_id', 'product_number'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('employees', ['tenant_id', 'employee_code'], 'unique'))->toBeTrue()
            ->and(collect(Schema::getForeignKeys('products'))->contains(fn (array $key): bool => $key['columns'] === ['category_id'] && $key['foreign_table'] === 'categories'))->toBeTrue()
            ->and(collect(Schema::getForeignKeys('tenants'))->contains(fn (array $key): bool => $key['columns'] === ['plan_id'] && $key['foreign_table'] === 'plans'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('original_schema_test');
    }
});

test('financial employee relationships prevent deleting their owner', function (string $table, string $column): void {
    $key = collect(Schema::getForeignKeys($table))->first(fn (array $key): bool => $key['columns'] === [$column]);
    expect(strtolower($key['on_delete']))->toBe('restrict');
})->with([
    ['employee_advances', 'employee_id'],
    ['employee_bonuses', 'employee_id'],
    ['employee_deductions', 'employee_id'],
    ['employee_payrolls', 'employee_id'],
    ['employee_payrolls', 'payroll_period_id'],
    ['employee_payroll_carryovers', 'employee_id'],
    ['employee_payments', 'employee_id'],
    ['employee_payments', 'employee_payroll_id'],
    ['employee_payments', 'employee_advance_id'],
    ['employee_payroll_items', 'employee_advance_id'],
    ['employee_payroll_items', 'employee_payroll_carryover_id'],
]);

test('product numbers can repeat across tenants but cannot repeat inside one tenant', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر أول']);
    $other = Tenant::query()->create(['name' => 'متجر ثان']);
    Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'أول', 'product_number' => 'ITEM-1']);
    Product::query()->create(['tenant_id' => $other->id, 'name' => 'ثان', 'product_number' => 'ITEM-1']);

    expect(fn () => DB::table('products')->insert(['tenant_id' => $tenant->id, 'name' => 'مكرر', 'product_number' => 'ITEM-1']))
        ->toThrow(QueryException::class);
});

test('employee codes are unique per tenant in both storage and the employee form', function (): void {
    $tenant = Tenant::query()->create(['name' => 'منشأة أولى']);
    $other = Tenant::query()->create(['name' => 'منشأة ثانية']);
    schemaEmployee($tenant);
    $this->actingAs(User::factory()->create(['tenant_id' => $other->id, 'is_owner' => true]));
    Livewire::test('pages::tenant.hr.payroll-manager')
        ->set('employee_code', 'EMP-001')->set('first_name', 'موظف')->set('last_name', 'جديد')
        ->set('hire_date', '2026-01-01')->call('saveEmployee')->assertHasNoErrors();

    expect(Employee::withoutGlobalScopes()->count())->toBe(2);
    expect(fn () => DB::table('employees')->insert(['tenant_id' => $other->id, 'employee_code' => 'EMP-001', 'first_name' => 'مكرر', 'last_name' => 'اختبار', 'hire_date' => '2026-01-01']))
        ->toThrow(QueryException::class);
});

test('branch stock rejects cross tenant products and branches even with a direct insert', function (bool $wrongBranch): void {
    $tenant = Tenant::query()->create(['name' => 'أول']);
    $other = Tenant::query()->create(['name' => 'ثان']);
    $branch = Branch::query()->create(['tenant_id' => $wrongBranch ? $other->id : $tenant->id, 'name' => 'فرع']);
    $product = Product::query()->create(['tenant_id' => $wrongBranch ? $tenant->id : $other->id, 'name' => 'صنف']);
    expect(fn () => DB::table('branch_products')->insert(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id]))
        ->toThrow(QueryException::class);
})->with([true, false]);

test('employees cannot link an account or branch from another tenant', function (bool $wrongAccount): void {
    $tenant = Tenant::query()->create(['name' => 'أول']);
    $other = Tenant::query()->create(['name' => 'ثان']);
    $branch = Branch::query()->create(['tenant_id' => $other->id, 'name' => 'فرع']);
    $user = User::factory()->create(['tenant_id' => $other->id]);
    expect(fn () => Employee::query()->create([
        'tenant_id' => $tenant->id, 'user_id' => $wrongAccount ? $user->id : null,
        'branch_id' => $wrongAccount ? null : $branch->id, 'employee_code' => 'EMP-001',
        'first_name' => 'موظف', 'last_name' => 'اختبار', 'hire_date' => '2026-01-01',
    ]))->toThrow(ValidationException::class);
})->with([true, false]);

test('payroll cannot use a period from a different tenant even outside a request', function (): void {
    $tenant = Tenant::query()->create(['name' => 'أول']);
    $other = Tenant::query()->create(['name' => 'ثان']);
    $employee = schemaEmployee($tenant);
    $period = PayrollPeriod::query()->create(['tenant_id' => $other->id, 'title' => 'راتب', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    expect(fn () => EmployeePayroll::query()->create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'net_salary' => 1000]))
        ->toThrow(HttpException::class);
});

test('employee payments cannot settle another employees advance', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر']);
    $employee = schemaEmployee($tenant);
    $other = schemaEmployee($tenant, 'EMP-002');
    $advance = EmployeeAdvance::query()->create(['employee_id' => $other->id, 'amount' => 100, 'installment_amount' => 50, 'start_installment_date' => '2026-10-01']);
    expect(fn () => EmployeePayment::query()->create(['employee_id' => $employee->id, 'employee_advance_id' => $advance->id, 'amount' => 100, 'payment_type' => 'advance', 'payment_date' => '2026-10-01']))
        ->toThrow(HttpException::class);
    expect(fn () => $other->delete())->toThrow(QueryException::class);
});

test('order lines reject products and invoices owned by another tenant', function (bool $wrongInvoice): void {
    $tenant = Tenant::query()->create(['name' => 'أول']);
    $other = Tenant::query()->create(['name' => 'ثان']);
    $order = Order::query()->create([
        'tenant_id' => $wrongInvoice ? $other->id : $tenant->id, 'invoice_number' => 'INV-1', 'subtotal' => 10, 'total' => 10,
    ]);
    $product = Product::query()->create(['tenant_id' => $wrongInvoice ? $tenant->id : $other->id, 'name' => 'صنف']);
    expect(fn () => DB::table('order_items')->insert([
        'tenant_id' => $tenant->id, 'order_id' => $order->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_price' => 10, 'total_price' => 10,
    ]))->toThrow(QueryException::class);
})->with([true, false]);

test('employee API permits the same code in different tenants and rejects local duplicates', function (): void {
    $tenant = Tenant::query()->create(['name' => 'أول']);
    $other = Tenant::query()->create(['name' => 'ثان']);
    schemaEmployee($tenant);
    $user = User::factory()->create(['tenant_id' => $other->id, 'is_owner' => true]);
    $data = [
        'employee_code' => 'EMP-001', 'first_name' => 'موظف', 'last_name' => 'اختبار',
        'employment_type' => 'full_time', 'salary_type' => 'monthly', 'hire_date' => '2026-01-01',
    ];
    $response = $this->actingAs($user)->postJson('/api/employees', $data)->assertCreated();
    $id = $response->json('employee.id');
    $this->patchJson('/api/employees/'.$id, ['employee_code' => 'EMP-001'])->assertOk();
    $this->postJson('/api/employees', $data)->assertUnprocessable()->assertJsonValidationErrors('employee_code');
});

test('payroll periods require a tenant in the database', function (): void {
    expect(fn () => DB::table('payroll_periods')->insert([
        'title' => 'دون منشأة', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
    ]))->toThrow(QueryException::class);
});

test('rolling back compatibility links preserves the original payroll columns', function (): void {
    $migration = require database_path('migrations/2026_10_23_143653_add_advance_and_carryover_links_to_payroll_items.php');
    $migration->up();
    $migration->down();
    expect(Schema::hasColumns('employee_payroll_items', ['employee_advance_id', 'employee_payroll_carryover_id']))->toBeTrue();
});

test('national identifiers are unique within each tenant', function (): void {
    $tenant = Tenant::query()->create(['name' => 'أول']);
    $other = Tenant::query()->create(['name' => 'ثان']);
    schemaEmployee($tenant)->update(['national_id' => '123456789']);
    schemaEmployee($other)->update(['national_id' => '123456789']);
    $duplicate = schemaEmployee($tenant, 'EMP-002');
    expect(fn () => $duplicate->update(['national_id' => '123456789']))->toThrow(QueryException::class);
});
