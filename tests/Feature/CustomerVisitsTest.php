<?php

use App\Models\Branch;
use App\Models\CustomerVisit;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Route shop', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'مندوب']);
    $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'wholesale_sales.view'], ['display_name' => 'x', 'group' => 'x']));
    $this->rep = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false, 'is_active' => true]);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true, 'is_active' => true]);
    $customer = fn (string $name, ?Branch $branch = null, string $type = 'customer'): Party => Party::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $branch?->id, 'type' => $type, 'name' => $name, 'is_active' => true, 'current_balance' => 50]);
    $this->shop = $customer('بقالة الأمل', $this->branch);
    $this->market = $customer('سوبرماركت النور');
    $this->farAway = $customer('زبون فرع آخر', $this->otherBranch);
    $this->supplier = $customer('مورد', null, 'supplier');
});

test('a rep builds today\'s customer list and records a sale, a collection or an order with a note', function (): void {
    $this->actingAs($this->rep);

    $this->postJson('/api/customer-visits', ['party_ids' => [$this->shop->id, $this->market->id]])->assertCreated()
        ->assertJsonCount(2, 'visits')->assertJsonPath('visits.0.customer.name', 'بقالة الأمل')->assertJsonPath('visits.0.outcome', null);
    // Adding the same customer again keeps one line.
    $this->postJson('/api/customer-visits', ['party_ids' => [$this->shop->id]])->assertCreated()->assertJsonCount(2, 'visits');

    $visit = CustomerVisit::query()->where('party_id', $this->market->id)->sole();
    $this->patchJson("/api/customer-visits/{$visit->id}", ['outcome' => 'order', 'notes' => 'يريد 10 كراتين غداً'])->assertOk()
        ->assertJsonPath('visit.outcome_label', 'يوجد طلبية')->assertJsonPath('visit.notes', 'يريد 10 كراتين غداً');
    $this->patchJson("/api/customer-visits/{$visit->id}", ['outcome' => 'teleport'])->assertUnprocessable();

    $this->getJson('/api/customer-visits')->assertOk()
        ->assertJsonPath('outcomes.collection', 'تحصيل فقط')
        ->assertJsonPath('visits.1.outcome', 'order')
        ->assertJsonPath('visits.1.customer.current_balance', 50);
    expect($visit->fresh()->visited_at)->not->toBeNull();

    // A recorded visit cannot be deleted; clearing its result first allows it.
    $this->deleteJson("/api/customer-visits/{$visit->id}")->assertStatus(422);
    $this->patchJson("/api/customer-visits/{$visit->id}", ['outcome' => null])->assertOk();
    $this->deleteJson("/api/customer-visits/{$visit->id}")->assertOk();
    expect(CustomerVisit::query()->count())->toBe(1);
});

test('reps only add their branch customers and only touch their own visits', function (): void {
    $this->actingAs($this->rep);
    $this->postJson('/api/customer-visits', ['party_ids' => [$this->farAway->id]])->assertNotFound();
    $this->postJson('/api/customer-visits', ['party_ids' => [$this->supplier->id]])->assertNotFound();

    $othersVisit = CustomerVisit::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'party_id' => $this->shop->id, 'visit_date' => today()]);
    $this->patchJson("/api/customer-visits/{$othersVisit->id}", ['outcome' => 'sale'])->assertNotFound();
    $this->getJson('/api/customer-visits')->assertJsonCount(0, 'visits');

    $cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => false,
        'role_id' => Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier'])->id]);
    $this->actingAs($cashier)->getJson('/api/customer-visits')->assertForbidden();
});

test('a manager prepares a rep\'s list on the web and follows what was recorded', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    $page = Livewire::test('pages::tenant.customer-visits.index')
        ->set('repId', $this->rep->id)
        ->set('customerSearch', 'بقالة')->assertSee('بقالة الأمل')
        ->call('addCustomer', $this->shop->id)
        ->assertSee('لم تُزر بعد');

    $page->assertSee('data-customer-result', false)->assertSee('data-manager-note', false);
    $visit = CustomerVisit::query()->sole();
    $page->call('saveManagerNote', $visit->id, '  حصّل 500  ')->call('saveManagerNote', $visit->id, str_repeat('x', 1001))
        ->assertHasErrors('managerNote.'.$visit->id);
    expect($visit->fresh()->manager_note)->toBe('حصّل 500');
    expect($visit->user_id)->toBe($this->rep->id)->and($visit->visit_date->toDateString())->toBe(today()->toDateString());

    $this->actingAs($this->rep);
    $this->getJson('/api/customer-visits')->assertJsonPath('visits.0.manager_note', 'حصّل 500');
    $this->patchJson("/api/customer-visits/{$visit->id}", ['outcome' => 'collection', 'notes' => 'دفع 200'])->assertOk();

    $this->actingAs($this->owner);
    $page->call('$refresh')->assertSee('تحصيل فقط')->assertSee('دفع 200');
    $page->call('removeVisit', $visit->id)->assertStatus(422);

    $this->actingAs($this->rep)->get(route('tenant.customer-visits'))->assertForbidden();
});
