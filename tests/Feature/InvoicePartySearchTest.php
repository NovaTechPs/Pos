<?php

use App\Models\Branch;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('invoice parties appear only after typing the first character', function (string $page, string $party): void {
    $tenant = Tenant::query()->create(['name' => 'متجر الفواتير']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الرئيسي']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);

    $matching = Party::query()->create(['tenant_id' => $tenant->id, 'type' => $party, 'name' => 'زيد', 'is_active' => true]);
    Party::query()->create(['tenant_id' => $tenant->id, 'type' => $party, 'name' => 'عمر', 'is_active' => true]);

    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->assertViewHas($party.'Results', fn ($results): bool => $results->isEmpty())
        ->assertDontSeeHtml('wire:click="select'.ucfirst($party).'('.$matching->id.')"')
        ->assertDispatched('focus-'.$party.'-search')
        ->set($party.'Search', 'ز')
        ->assertViewHas($party.'Results', fn ($results): bool => $results->pluck('id')->all() === [$matching->id])
        ->assertSeeHtml('wire:click="select'.ucfirst($party).'('.$matching->id.')"')
        ->set($party.'Search', '   ')
        ->assertViewHas($party.'Results', fn ($results): bool => $results->isEmpty())
        ->set($party.'Search', 'ز')
        ->call('select'.ucfirst($party), $matching->id)
        ->assertDontSeeHtml('wire:click="select'.ucfirst($party).'('.$matching->id.')"')
        ->assertViewHas($party.'Results', fn ($results): bool => $results->isEmpty())
        ->assertDispatched('focus-product-search');

    $component->call('clear'.ucfirst($party))
        ->assertSet($party.'Search', '')
        ->assertDispatched('focus-'.$party.'-search')
        ->call('startNewInvoice')
        ->assertDispatched('focus-'.$party.'-search');
})->with([
    'sales customer' => ['sales', 'customer'],
    'purchase supplier' => ['purchases', 'supplier'],
]);
