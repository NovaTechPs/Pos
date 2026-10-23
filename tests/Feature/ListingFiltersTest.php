<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Filters tenant']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

test('products default to product number order with blank numbers last and can search product numbers', function (): void {
    foreach ([['I00000010', 'Ten'], [null, 'No number'], ['I00000002', 'Two'], ['', 'Blank']] as [$number, $name]) {
        Product::query()->create(['tenant_id' => $this->tenant->id, 'product_number' => $number, 'name' => $name]);
    }
    $otherTenant = Tenant::query()->create(['name' => 'Other products tenant']);
    Product::query()->withoutGlobalScopes()->create(['tenant_id' => $otherTenant->id, 'name' => 'Outside product', 'product_number' => 'I00000001']);
    $component = Livewire::test('pages::tenant.products.index')->assertSee('رقم الصنف تصاعديًا')
        ->assertViewHas('products', function ($products): bool {
            expect($products->pluck('name')->all())->toBe(['Two', 'Ten', 'No number', 'Blank']);

            return true;
        });
    $component->set('search', 'I00000002')->assertViewHas('products', function ($products): bool {
        expect($products->pluck('name')->all())->toBe(['Two']);

        return true;
    });
    $component->call('clearFilters')->set('sort', 'number_desc')
        ->assertViewHas('products', function ($products): bool {
            expect($products->pluck('name')->all())->toBe(['Ten', 'Two', 'No number', 'Blank']);

            return true;
        });
});

test('product stock category and website filters combine within the active branch and reset pagination', function (): void {
    $category = Category::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Matching category', 'is_active' => true]);
    foreach (['Matching' => 2, 'No stock' => 0, 'Other category' => 5] as $name => $quantity) {
        $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => $name, 'category_id' => $name === 'Other category' ? null : $category->id, 'show_in_website' => true]);
        BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'stock_quantity' => $quantity, 'alert_quantity' => 3, 'retail_price' => $name === 'Matching' ? 0 : 10]);
        BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->otherBranch->id, 'product_id' => $product->id, 'stock_quantity' => 99, 'retail_price' => 10]);
    }
    $component = Livewire::test('pages::tenant.products.index')->call('setPage', 2)->set('stockFilter', 'available')
        ->assertSet('paginators.page', 1)->set('selectedCategoryFilter', (string) $category->id)->set('selectedWebsiteFilter', '1')
        ->assertViewHas('products', fn ($products): bool => $products->pluck('name')->all() === ['Matching']);
    $component->set('stockFilter', 'empty')->assertViewHas('products', fn ($products): bool => $products->pluck('name')->all() === ['No stock']);
    $component->set('stockFilter', 'unpriced')->assertViewHas('products', fn ($products): bool => $products->pluck('name')->all() === ['Matching']);
    $component->call('clearFilters')->assertSet('stockFilter', '')->assertSet('sort', 'product_number');
});

test('customers combine type activity balance and search filters without leaking tenants', function (): void {
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Matching customer', 'type' => 'customer', 'is_active' => true, 'current_balance' => 50]);
    Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Supplier', 'type' => 'supplier', 'is_active' => true, 'current_balance' => -5]);
    Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Inactive', 'type' => 'customer', 'is_active' => false, 'current_balance' => 0]);
    $otherTenant = Tenant::query()->create(['name' => 'Outside']);
    Party::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Matching outsider', 'type' => 'customer', 'is_active' => true, 'current_balance' => 50]);
    $component = Livewire::test('pages::tenant.customers.index')->call('setPage', 3)->set('typeFilter', 'customer')->assertSet('paginators.page', 1)
        ->set('balanceFilter', 'debit')->set('search', 'Matching')->assertViewHas('parties', fn ($parties): bool => $parties->modelKeys() === [$customer->id]);
    $component->call('clearFilters')->set('statusFilter', 'inactive')->set('balanceFilter', 'settled')
        ->assertViewHas('parties', fn ($parties): bool => $parties->pluck('name')->all() === ['Inactive']);
    $component->call('clearFilters')->set('balanceFilter', 'credit')->assertViewHas('parties', fn ($parties): bool => $parties->pluck('name')->all() === ['Supplier']);
});

test('voucher filters combine dates method amount and source and can be cleared', function (string $page, string $type): void {
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'FILTER-INVOICE', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 25, 'total' => 25]);
    $matching = null;
    foreach ([['2026-10-20 23:59:00', 'card', 25, $order->id], ['2026-10-19', 'card', 25, $order->id], ['2026-10-20', 'cash', 100, null]] as $index => [$date, $method, $amount, $orderId]) {
        $payment = Payment::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => $type, 'voucher_number' => 'FILTER-'.$index, 'amount' => $amount, 'payment_method' => $method, 'payment_date' => $date, 'order_id' => $orderId]);
        $matching ??= $payment;
    }
    $component = Livewire::test($page)->assertSee('مصدر السند')->call('setPage', 2)
        ->set('filterDateFrom', '2026-10-20')->assertSet('paginators.page', 1)->set('filterDateTo', '2026-10-20')
        ->set('filterMethod', 'card')->set('filterMinAmount', '0')->set('filterMaxAmount', '25')->set('filterSource', 'invoice');
    expect($component->get('items')->pluck('id')->all())->toBe([$matching->id]);
    $component->call('clearVoucherFilters');
    expect($component->get('items')->count())->toBe(3);
    $component->set('filterSource', 'manual');
    expect($component->get('items')->count())->toBe(1);
    $component->call('clearVoucherFilters')->set('filterDateFrom', 'not-a-date')->set('filterMaxAmount', 'bad');
    expect($component->get('items')->count())->toBe(3);
})->with([
    ['pages::tenant.accounting.receipts', 'receipt'],
    ['pages::tenant.accounting.payment', 'payment'],
]);

test('transfer date and direction filters combine with status and preserve tenant scope', function (): void {
    $transfer = StockTransfer::query()->create(['tenant_id' => $this->tenant->id, 'from_branch_id' => $this->branch->id, 'to_branch_id' => $this->otherBranch->id, 'transfer_number' => 'TR-MATCH', 'status' => 'completed', 'transfer_date' => '2026-10-20 23:59:00']);
    StockTransfer::query()->create(['tenant_id' => $this->tenant->id, 'from_branch_id' => $this->otherBranch->id, 'to_branch_id' => $this->branch->id, 'transfer_number' => 'TR-OTHER', 'status' => 'pending', 'transfer_date' => '2026-10-19']);
    Livewire::test('pages::tenant.stock-transfers.index')->call('setPage', 2)->set('sourceBranchFilter', $this->branch->id)
        ->assertSet('paginators.page', 1)->set('destinationBranchFilter', $this->otherBranch->id)->set('statusFilter', 'completed')
        ->set('dateFrom', '2026-10-20')->set('dateTo', '2026-10-20')
        ->assertViewHas('transfers', fn ($transfers): bool => $transfers->pluck('id')->all() === [$transfer->id])
        ->call('clearListFilters')->assertViewHas('transfers', fn ($transfers): bool => $transfers->total() === 2);
});

test('payroll list filters employees and attendance while leaving employee choices available', function (): void {
    $employee = Employee::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'employee_code' => 'EMP1', 'first_name' => 'Filter', 'last_name' => 'Worker', 'hire_date' => today(), 'is_active' => true]);
    $other = Employee::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'employee_code' => 'EMP2', 'first_name' => 'Other', 'last_name' => 'Worker', 'hire_date' => today(), 'is_active' => true]);
    foreach ([$employee, $other] as $worker) {
        EmployeeAttendance::query()->create(['employee_id' => $worker->id, 'date' => today()->toDateString(), 'status' => 'present']);
    }
    Livewire::test('pages::tenant.hr.payroll-manager')->set('employeeSearchFilter', 'EMP1')
        ->assertViewHas('employees', fn ($employees): bool => $employees->pluck('id')->all() === [$employee->id])
        ->set('activeTab', 'attendance')->set('employeeListFilter', $employee->id)
        ->assertViewHas('attendances', fn ($records): bool => $records->pluck('employee_id')->all() === [$employee->id])
        ->call('clearListFilters')->assertViewHas('attendances', fn ($records): bool => $records->count() === 2);
});

test('customer sorting uses balances and returns to first page when changing sort', function (): void {
    foreach (['Low' => -10, 'High' => 80, 'Zero' => 0] as $name => $balance) {
        Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => $name, 'type' => 'customer', 'is_active' => true, 'current_balance' => $balance]);
    }
    Livewire::test('pages::tenant.customers.index')->call('setPage', 4)->set('sort', 'balance_desc')
        ->assertSet('paginators.page', 1)->assertViewHas('parties', fn ($parties): bool => $parties->pluck('name')->all() === ['High', 'Zero', 'Low'])
        ->set('sort', 'balance_asc')->assertViewHas('parties', fn ($parties): bool => $parties->pluck('name')->all() === ['Low', 'Zero', 'High']);
});
