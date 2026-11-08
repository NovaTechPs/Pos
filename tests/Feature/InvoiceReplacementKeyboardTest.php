<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

test('successful inline replacement returns focus to the new product search', function (): void {
    $tenant = Tenant::create(['name' => 'المتجر']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    $products = collect(['الصنف الأصلي', 'الصنف البديل'])->map(function (string $name) use ($tenant, $branch): Product {
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => $name, 'cost_price' => 5]);
        BranchProduct::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'retail_price' => 10, 'stock_quantity' => 20]);

        return $product;
    });
    $component = Livewire::test('pages::tenant.sales.invoices')->call('selectProduct', $products[0]->id)->call('commitRowToCart');
    $key = array_key_first($component->get('cart'));
    $component->call('openInlineReplacement', $key)->set('replacementSearch', 'البديل')
        ->assertSee('data-replacement-result', false)
        ->assertSee('x-on:keydown.arrow-down.prevent.stop', false)
        ->assertSee('x-on:keydown.arrow-up.prevent.stop', false)
        ->assertSee('x-on:keydown.enter.prevent.stop', false)
        ->call('replaceCartItem', $products[1]->id)->assertHasNoErrors()
        ->assertSet('replacingCartKey', null)->assertSet('replacementSearch', '')
        ->assertSet('cart.'.$key.'.id', $products[1]->id)
        ->assertDispatched('focus-product-search');
    $component->call('replaceCartItem', $products[0]->id)->assertNotDispatched('focus-product-search');
});

test('replacement keyboard selects scrolls and handles refreshed empty and disabled results', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('fs');
    const vm = require('vm');
    const assert = require('assert/strict');
    const source = fs.readFileSync('resources/views/pages/tenant/sales/⚡invoices.blade.php', 'utf8');
    const script = source.slice(source.indexOf('<script>') + 8, source.lastIndexOf('</script>'));
    let factory;
    const handlers = {};
    vm.runInNewContext(script, {
        Alpine: { data: (name, fn) => { factory = fn; } },
        Livewire: { on: (name, fn) => { handlers[name] = fn; }, hook: () => {} },
        window: { addEventListener: () => {} },
        AbortController, setTimeout: () => 0, clearTimeout: () => {},
        requestAnimationFrame: fn => fn(),
    });
    const state = factory();
    function result() {
        const classes = new Set();
        return { disabled: false, clicks: 0, scrolls: 0, classes,
            getClientRects: () => [1],
            classList: { toggle: (key, on) => on ? classes.add(key) : classes.delete(key) },
            click() { this.clicks++; }, scrollIntoView() { this.scrolls++; },
        };
    }
    let rows = [result(), result(), result()];
    const mainInput = { focused: false, selected: false, focus() { this.focused = true; }, select() { this.selected = true; } };
    state.$el = { isConnected: true,
        querySelectorAll: selector => selector === '[data-replacement-result]' ? rows : [],
        querySelector: selector => selector === '#product-search-input' ? mainInput : null,
        contains: element => element === mainInput,
    };
    state.$nextTick = fn => fn();
    state.$root = state.$el;
    state.init();
    const eventScope = new Proxy(state, {
        get(target, key, receiver) {
            if (key === '$el') return { querySelectorAll: () => [] };
            return Reflect.get(target, key, receiver);
        },
    });
    eventScope.moveResult('replacement', 1);
    assert.equal(state.replacementIndex, 0);
    eventScope.moveResult('replacement', 1);
    assert.equal(state.replacementIndex, 1);
    assert.equal(rows[1].classes.has('search-result-active'), true);
    assert.equal(rows[0].classes.has('search-result-active'), false);
    assert.equal(rows[1].scrolls, 1);
    assert.equal(eventScope.clickCurrentResult('replacement'), true);
    assert.equal(rows[1].clicks, 1);
    rows[1].disabled = true;
    assert.equal(eventScope.clickCurrentResult('replacement'), false);
    assert.equal(rows[1].clicks, 1);
    eventScope.moveResult('replacement', -1);
    assert.equal(state.replacementIndex, 0);
    state.replacementIndex = -1;
    eventScope.moveResult('replacement', -1);
    assert.equal(state.replacementIndex, 2);
    rows = [result()];
    eventScope.refreshResultClasses();
    assert.equal(state.replacementIndex, 0);
    rows = [];
    eventScope.refreshResultClasses();
    eventScope.moveResult('replacement', 1);
    assert.equal(eventScope.clickCurrentResult('replacement'), false);
    assert.equal(state.replacementIndex, -1);
    rows = [result()];
    assert.equal(eventScope.clickCurrentResult('replacement'), true);
    assert.equal(rows[0].clicks, 1);
    handlers['focus-product-search']();
    assert.equal(mainInput.focused, true);
    assert.equal(mainInput.selected, true);
    JAVASCRIPT;
    $process = new Process(['node', '-e', $script], base_path());
    $process->run();
    expect($process->getErrorOutput())->toBe('')->and($process->isSuccessful())->toBeTrue();
});
