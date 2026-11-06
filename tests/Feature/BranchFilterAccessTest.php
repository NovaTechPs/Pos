<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر فلاتر الفروع']);
    $this->firstBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الأول']);
    $this->secondBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الثاني']);
    $this->owner = User::factory()->create([
        'type' => 'tenant_user',
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->firstBranch->id,
        'is_owner' => true,
    ]);
    $this->actingAs($this->owner)->withSession([
        'active_tenant_id' => $this->tenant->id,
        'active_branch_id' => $this->firstBranch->id,
    ]);
});

test('owner can view all branches or narrow reports to one branch without changing the active branch', function (): void {
    foreach ([[$this->firstBranch->id, 'FILTER-PUR-A', 10], [$this->secondBranch->id, 'FILTER-PUR-B', 20]] as [$branchId, $reference, $total]) {
        DB::table('purchases')->insert([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'reference_number' => $reference,
            'total' => $total,
            'paid_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $filters = [
        'from' => today()->toDateString(),
        'to' => today()->toDateString(),
        'party_id' => null,
        'product_id' => null,
        'search' => '',
        'party_type' => 'all',
        'balance_side' => 'all',
        'stock_filter' => 'all',
        'source' => '',
        'sale_kind' => '',
    ];
    $allBranches = (new ReportingService($this->owner, null, false))->prepare('purchases', $filters);
    $singleBranch = (new ReportingService($this->owner, $this->secondBranch->id, false))->prepare('purchases', $filters);

    Livewire::test('pages::tenant.reports.center')
        ->assertSet('branchId', null)
        ->assertSee('كل الفروع')
        ->set('branchId', $this->secondBranch->id)
        ->assertSet('branchId', $this->secondBranch->id);

    expect($allBranches['query']->count())->toBe(2)
        ->and($singleBranch['query']->count())->toBe(1)
        ->and(session('active_branch_id'))->toBe($this->firstBranch->id);
});

test('owner searches sales invoices across all branches or within a selected branch', function (): void {
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->secondBranch->id,
        'invoice_number' => 'WHOLESALE-OTHER-BRANCH',
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 20,
        'total' => 20,
        'paid_amount' => 20,
        'total_cost' => 10,
    ]);

    Livewire::test('pages::tenant.sales.invoices')
        ->set('invoiceBranchFilter', $this->firstBranch->id)
        ->set('searchInvoiceQuery', 'WHOLESALE-OTHER-BRANCH')
        ->call('searchInvoice')
        ->assertSet('currentOrderId', null)
        ->set('invoiceBranchFilter', null)
        ->set('searchInvoiceQuery', 'WHOLESALE-OTHER-BRANCH')
        ->call('searchInvoice')
        ->assertSet('currentOrderId', $order->id);
});

test('owner searches purchase invoices across all branches or within a selected branch', function (): void {
    $purchaseId = DB::table('purchases')->insertGetId([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->secondBranch->id,
        'reference_number' => 'PURCHASE-OTHER-BRANCH',
        'total' => 20,
        'paid_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('pages::tenant.purchases.invoices')
        ->set('purchaseBranchFilter', $this->firstBranch->id)
        ->set('searchPurchaseQuery', 'PURCHASE-OTHER-BRANCH')
        ->call('searchPurchase')
        ->assertSet('currentPurchaseId', null)
        ->set('purchaseBranchFilter', null)
        ->set('searchPurchaseQuery', 'PURCHASE-OTHER-BRANCH')
        ->call('searchPurchase')
        ->assertSet('currentPurchaseId', $purchaseId);
});

test('owner analytics starts with all branches and filters its invoice list by branch', function (): void {
    foreach ([[$this->firstBranch->id, 'ANALYTICS-BRANCH-A'], [$this->secondBranch->id, 'ANALYTICS-BRANCH-B']] as [$branchId, $invoiceNumber]) {
        Order::query()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'invoice_number' => $invoiceNumber,
            'type' => 'pos',
            'status' => 'completed',
            'subtotal' => 20,
            'total' => 20,
            'paid_amount' => 20,
            'total_cost' => 10,
            'created_at' => now(),
        ]);
    }

    Livewire::test('pages::tenant.reports.analytics')
        ->assertSet('selectedBranchId', null)
        ->assertSee('ANALYTICS-BRANCH-A')
        ->assertSee('ANALYTICS-BRANCH-B')
        ->set('selectedBranchId', $this->firstBranch->id)
        ->assertSee('ANALYTICS-BRANCH-A')
        ->assertDontSee('ANALYTICS-BRANCH-B');
});
