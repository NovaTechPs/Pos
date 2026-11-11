<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر العروض']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

test('every add-offer form opts into arrow-key navigation', function (): void {
    $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'الصنف', 'cost_price' => 5]);

    Livewire::test('pages::tenant.products.index')->call('openOfferModal', $product->id)
        ->assertSee('wire:submit.prevent="saveOffer" data-arrow-nav', false);
    Livewire::test('pages::tenant.products.index')->call('openCreateModal')
        ->assertSee('data-product-new-offer data-arrow-nav', false);
    Livewire::test('pages::tenant.offers.index')->call('openCreateModal')
        ->assertSee('wire:submit="save" data-arrow-nav', false);

    expect(file_get_contents(resource_path('js/app.js')))->toContain("import './form-arrow-navigation.js';");
});

test('arrow keys and enter move between offer fields and into search results', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('fs');
    const vm = require('vm');
    const assert = require('assert/strict');
    const source = fs.readFileSync(process.argv[1], 'utf8');
    let listener;
    class FakeElement {
        constructor(name, tag = 'input', type = 'text') { this.name = name; this.tag = tag; this.type = type; this.focused = 0; this.selected = 0; }
        getClientRects() { return this.hidden ? [] : [{}]; }
        closest(selector) { if (selector === '[data-arrow-nav]') return container; return null; }
        matches(selector) { return this.tag === 'button' && selector.includes('button'); }
        focus() { this.focused++; focused = this; }
        select() { this.selected++; }
    }
    let focused = null;
    const fields = [new FakeElement('quantity'), new FakeElement('result', 'button'), new FakeElement('price'), new FakeElement('start', 'input', 'datetime-local')];
    fields[1].hidden = false;
    const container = { querySelectorAll: () => fields };
    vm.runInNewContext(source, {
        document: { addEventListener: (type, fn) => { listener = fn; } },
        HTMLElement: FakeElement, HTMLInputElement: FakeElement,
    });
    const press = (target, key, extra = {}) => { const event = { key, target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra }; listener(event); return event; };

    assert.ok(press(fields[0], 'ArrowDown').defaultPrevented);
    assert.equal(focused, fields[1]);
    assert.equal(press(fields[1], 'Enter').defaultPrevented, false, 'Enter on a result button keeps clicking it');
    press(fields[1], 'ArrowDown');
    assert.equal(focused, fields[2]);
    press(fields[2], 'ArrowUp');
    assert.equal(focused, fields[1]);
    press(fields[2], 'Enter');
    assert.equal(focused, fields[3]);
    assert.ok(press(fields[3], 'ArrowDown').defaultPrevented, 'last field keeps focus without changing its value');
    assert.equal(focused, fields[3]);
    assert.equal(press(fields[0], 'ArrowDown', { shiftKey: true }).defaultPrevented, false);
    fields[1].hidden = true;
    press(fields[0], 'ArrowDown');
    assert.equal(focused, fields[2], 'hidden results are skipped');
    console.log('OFFER_ARROW_NAV_OK');
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script, resource_path('js/form-arrow-navigation.js')]);
    $process->mustRun();

    expect($process->getOutput())->toContain('OFFER_ARROW_NAV_OK');
});
