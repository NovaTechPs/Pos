<?php

use App\Models\Branch;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $tenant = Tenant::create(['name' => 'متجر الكشف الجانبي']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    $this->party = Party::create(['tenant_id' => $tenant->id, 'name' => 'ابراهيم اشتيه', 'type' => 'customer', 'opening_balance' => 0, 'current_balance' => 1250.5, 'is_active' => true]);
});

test('customers list keeps the statement closed until a customer is picked', function (): void {
    $html = Livewire::test('pages::tenant.customers.index')->html();

    expect($html)->toContain('data-customers-workspace')
        ->not->toContain('has-statement', 'data-customer-statement', 'data-statement-party-active');
});

test('picking a customer shows the statement beside the compact list', function (): void {
    $html = Livewire::test('pages::tenant.customers.index')->call('openStatementModal', $this->party->id)->html();

    $workspace = substr($html, strpos($html, 'data-customers-workspace'));
    expect($workspace)->toContain('customers-workspace has-statement', 'data-statement-party-active', 'data-customer-statement')
        ->and(strpos($workspace, 'customers-list-pane'))->toBeLessThan(strpos($workspace, 'data-customer-statement'))
        ->and(substr_count($html, 'data-party-extra'))->toBeGreaterThanOrEqual(10)
        ->and($html)->toContain('x-on:click="if (! $event.target.closest(');

    expect(file_get_contents(resource_path('css/customer-statement.css')))
        ->toContain('.customers-workspace.has-statement{display:grid', '.customers-workspace.has-statement [data-party-extra]{display:none}', '.customers-workspace.has-statement [data-customer-statement]{position:static');
});
