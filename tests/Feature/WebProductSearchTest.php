<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر البحث']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'قَهْوَة إيطالية فاتحة', 'product_number' => 'COFFEE-42', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 20, 'retail_price' => 5, 'wholesale_price' => 4]);
    ProductBarcode::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'barcode' => '1234567890123']);
});

test('clicking a purchase search result adds that product to the invoice', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')->set('rowSearch', 'قهوه')
        ->assertSee('wire:click="addProductFromSearch('.$this->product->id.')"', false)
        ->assertSee('x-on:mousedown.prevent', false)
        ->call('addProductFromSearch', $this->product->id)
        ->assertSet('rowSearch', '')->assertSet('selectedProductId', null)->assertSet('errorMessage', null)
        ->assertDispatched('focus-product-search');
    $items = array_values($component->get('cart'));
    expect($items)->toHaveCount(1)->and($items[0]['id'])->toBe($this->product->id)
        ->and($items[0]['quantity'])->toBe(1.0)->and($items[0]['price'])->toBe(2.0);
    $otherTenant = Tenant::create(['name' => 'متجر آخر']);
    $otherProduct = Product::create(['tenant_id' => $otherTenant->id, 'name' => 'صنف مخفي']);
    $component->call('addProductFromSearch', $otherProduct->id);
    expect($component->get('cart'))->toHaveCount(1);
});

test('invoice search uses POS matching for Arabic words numbers and barcodes', function (string $page, string $search): void {
    Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف لا يطابق']);
    Livewire::test('pages::tenant.'.$page.'.invoices')->set('rowSearch', $search)
        ->assertViewHas('productResults', fn ($results): bool => $results->pluck('id')->all() === [$this->product->id]);
})->with(['sales', 'purchases'])->with(['فاتحه قهوه', 'ايطاليه', 'coffee-42', '1234567890123']);

test('products page search uses POS matching for Arabic words numbers and barcodes', function (string $search): void {
    Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف لا يطابق']);

    Livewire::test('pages::tenant.products.index')->set('search', $search)
        ->assertViewHas('products', fn ($products): bool => $products->pluck('id')->all() === [$this->product->id]);
})->with(['فاتحه قهوه', 'ايطاليه', 'coffee-42', '1234567890123']);

test('barcode printer selects products from a search dropdown', function (): void {
    $component = Livewire::test('pages::tenant.products.printer')
        ->set('productSearch', 'coffee-42')
        ->assertSee('wire:click="selectProduct('.$this->product->id.')"', false)
        ->assertSee('x-on:click.outside="open = false"', false)
        ->assertDontSee('wire:model.live="selectedProductId"', false)
        ->call('selectProduct', $this->product->id)
        ->assertSet('selectedProductId', $this->product->id)
        ->assertSet('selectedBarcode', '1234567890123');

    expect($component->get('productSearch'))->toBe($this->product->name);
});

test('Enter moves to the next product field without submitting the form', function (): void {
    $html = Livewire::test('pages::tenant.products.index')->assertSet('showModal', false)->html();

    expect(substr_count($html, 'fields[index + 1]?.focus()'))->toBe(2)
        ->and($html)->toContain('if ($event.target.matches(\'textarea\')) return')
        ->and($html)->toContain('wire:submit.prevent="save"')
        ->and($html)->toContain('type="submit"');
});

test('invoice search can replace a selected product without leaving stale prices', function (string $page): void {
    Livewire::test('pages::tenant.'.$page.'.invoices')->call('selectProduct', $this->product->id)
        ->set('rowSearch', 'فاتحه قهوه')->assertSet('selectedProductId', null)
        ->assertViewHas('productResults', fn ($results): bool => $results->pluck('id')->all() === [$this->product->id]);
})->with(['sales', 'purchases']);

test('invoice keyboard ignores hidden suggestions and cleans up repeated work', function (): void {
    $script = <<<'JS'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const visible = { getClientRects: () => [{}] };
    const hidden = { getClientRects: () => [] };
    for (const page of ['sales', 'purchases']) {
        const source = fs.readFileSync(`${process.argv[1]}/${page}/⚡invoices.blade.php`, 'utf8');
        const start = source.indexOf("Alpine.data('");
        const end = source.indexOf('}));', start) + 4;
        let state, morph, listenerOptions;
        const timers = new Map();
        let timerId = 0;
        const context = {
            Alpine: { data: (_, factory) => state = factory() },
            Livewire: { on() {}, hook: (_, callback) => morph = callback },
            window: { addEventListener: (_, callback, options) => listenerOptions = options },
            AbortController,
            setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
            clearTimeout: id => timers.delete(id),
        };
        vm.runInNewContext(source.slice(start, end), context);
        state.$el = { isConnected: true, contains: () => true, querySelectorAll: () => [visible, hidden] };
        state.$nextTick = () => {};
        state.$refs = {};
        if (page === 'sales') {
            assert.equal(state.productResults().length, 1);
            assert.equal(state.customerResults().length, 1);
            state.refreshResultClasses = () => {};
            state.init();
            for (let i = 0; i < 100; i++) morph({ el: {} });
            assert.equal(timers.size, 1, 'Repeated DOM updates queued redundant refreshes');
        } else {
            assert.equal(state.visibleResults('[data-product-result]').length, 1);
            state.registerKeyboard();
            assert.equal(listenerOptions.signal.aborted, false);
            state.destroy();
            assert.equal(listenerOptions.signal.aborted, true);
        }
    }
    console.log('INVOICE_SEARCH_KEYBOARD_OK');
    JS;
    $process = new Process(['node', '-e', $script, resource_path('views/pages/tenant')]);
    $process->mustRun();
    expect($process->getOutput())->toContain('INVOICE_SEARCH_KEYBOARD_OK');
});

test('invoice search prioritizes exact barcodes and bounds excessive searches', function (string $page): void {
    Product::create(['tenant_id' => $this->tenant->id, 'name' => '1234567890123']);
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')->set('rowSearch', '1234567890123')
        ->assertViewHas('productResults', fn ($results): bool => $results->first()->id === $this->product->id);
    $component->set('rowSearch', str_repeat('ا', 121))->assertViewHas('productResults', fn ($results): bool => $results->isEmpty());
})->with(['sales', 'purchases']);

test('invoice product and party menus close locally outside and reopen when searching', function (string $page): void {
    $html = Livewire::test('pages::tenant.'.$page.'.invoices')->set('rowSearch', 'قهوه')->html();
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@*[name()="x-on:click.outside"]="productSearchOpen = false"]')->length)->toBe(1)
        ->and($xpath->query('//*[@*[name()="x-on:click.outside"]="partySearchOpen = false"]')->length)->toBe(1)
        ->and($xpath->query('//*[@*[name()="x-show"]="productSearchOpen"]')->length)->toBe(1);
    expect($html)->toContain('wire:model.live.debounce.250ms="rowSearch"', 'x-on:focus="productSearchOpen = true"');
    foreach (['partySearchOpen', 'productSearchOpen'] as $state) {
        $group = $xpath->query('//*[@data-invoice-search-group and @*[name()="x-data"]="{ '.$state.': false }"]')->item(0);
        expect($group)->not->toBeNull();
        $handler = $group->getAttribute('x-on:focusout');
        expect($handler)->toBe('if (!$el.contains($event.relatedTarget)) '.$state.' = false');
        $script = 'const assert = require("node:assert/strict");'
            .'const evaluate = new Function("$el", "$event", "'.$state.'", '.json_encode($handler.'; return '.$state.';').');'
            .'assert.equal(evaluate({contains: () => false}, {relatedTarget: null}, true), false);'
            .'assert.equal(evaluate({contains: () => false}, {relatedTarget: {}}, true), false);'
            .'assert.equal(evaluate({contains: () => true}, {relatedTarget: {}}, true), true);';
        (new Process(['node', '-e', $script]))->mustRun();
    }
    expect(file_get_contents(resource_path('css/app.css')))->toContain('[data-invoice-search-group]:not(:focus-within) [data-invoice-search-results]');
})->with(['sales', 'purchases']);
