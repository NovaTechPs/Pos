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
        ->assertSee('max-h-72 w-full overflow-y-auto', false)
        ->assertSee('x-on:mouseenter="activeIndex = results().indexOf($el)"', false)
        ->assertSee('cursor-pointer', false)
        ->assertSee('x-on:click.outside="open = false; activeIndex = -1"', false)
        ->assertDontSee('wire:model.live="selectedProductId"', false)
        ->call('selectProduct', $this->product->id)
        ->assertSet('selectedProductId', $this->product->id)
        ->assertSet('selectedBarcode', '1234567890123');

    expect($component->get('productSearch'))->toBe($this->product->name);
});

test('barcode printer search matches normalized names product numbers and barcodes', function (string $search): void {
    Livewire::test('pages::tenant.products.printer')
        ->set('productSearch', $search)
        ->assertSee('wire:click="selectProduct('.$this->product->id.')"', false);
})->with(['قهوه', 'فاتحه', 'coffee-42', '1234567890123']);

test('barcode labels use the selected branch retail price instead of cost price', function (): void {
    $component = Livewire::test('pages::tenant.products.printer')->call('selectProduct', $this->product->id);

    expect($component->instance()->getRetailPrice())->toBe(5.0);
});
test('barcode printer keeps the barcode digits within the label height', function (): void {
    $template = file_get_contents(resource_path('views/pages/tenant/products/⚡printer.blade.php'));

    expect($template)->toContain('textPosition:')
        ->and(preg_match_all('/fontSize:\s+15,/', $template))->toBe(1)
        ->and(preg_match_all('/font: \'Arial\', fontSize:\s+16,/', $template))->toBe(1);
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
        const end = source.indexOf('\n    }));', start) + '\n    }));'.length;
        let state, morph, listenerOptions;
        const listeners = [];
        const timers = new Map();
        let timerId = 0;
        const context = {
            Alpine: { data: (_, factory) => state = factory() },
            Livewire: { on() {}, hook: (_, callback) => morph = callback },
            window: { addEventListener: (_, callback, options) => { listenerOptions = options; listeners.push({ callback, options }); } },
            AbortController,
            setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
            clearTimeout: id => timers.delete(id),
        };
        vm.runInNewContext(source.slice(start, end), context);
        state.$el = { isConnected: true, contains: () => true, addEventListener() {}, querySelectorAll: () => [visible, hidden] };
        state.$nextTick = () => {};
        state.$refs = {};
        if (page === 'sales') {
            assert.equal(state.productResults().length, 1);
            assert.equal(state.customerResults().length, 1);
            state.refreshResultClasses = () => {};
            state.init();
            state.init();
            assert.equal(listeners.length, 1, 'Initialization registered duplicate keyboard handlers');
            assert(!source.includes('x-init="init()"'), 'Alpine already calls init automatically');
            const rows = [0, 1, 2, 3].map(() => ({ scrollIntoView() {} }));
            state.productResults = () => rows;
            const target = { matches: selector => selector === '#product-search-input', closest: () => null };
            const press = key => listeners.filter(({ options }) => !options.signal.aborted)
                .forEach(({ callback }) => callback({ key, target, preventDefault() {} }));
            press('ArrowDown');
            assert.equal(state.productIndex, 0);
            press('ArrowDown');
            assert.equal(state.productIndex, 1);
            press('ArrowDown');
            assert.equal(state.productIndex, 2);
            press('ArrowUp');
            assert.equal(state.productIndex, 1);

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
    $delay = $page === 'purchases' ? 0 : 250;
    expect($html)->toContain('wire:model.live.debounce.'.$delay.'ms="rowSearch"', 'x-on:focus="productSearchOpen = true"');
    if ($page === 'purchases') {
        expect($html)->not->toContain('wire:model.live="discount"', '>الخصم</th>');
    }
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

test('invoice search finds products by any part of their barcode', function (string $page, string $search): void {
    Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف آخر', 'product_number' => 'OTHER-1', 'cost_price' => 1]);

    Livewire::test('pages::tenant.'.$page.'.invoices')->set('rowSearch', $search)
        ->assertViewHas('productResults', fn ($results): bool => $results->pluck('id')->all() === [$this->product->id]);
})->with(['sales', 'purchases'])->with(['90123', '٩٠١٢٣', '456789']);

test('invoice search ignores barcode fragments that match nothing or are too short', function (string $page): void {
    Livewire::test('pages::tenant.'.$page.'.invoices')->set('rowSearch', '99999')
        ->assertViewHas('productResults', fn ($results): bool => $results->isEmpty());

    $other = Product::create(['tenant_id' => Tenant::create(['name' => 'متجر آخر'])->id, 'name' => 'صنف متجر آخر', 'cost_price' => 1]);
    ProductBarcode::create(['tenant_id' => $other->tenant_id, 'product_id' => $other->id, 'barcode' => '5550001112223']);

    Livewire::test('pages::tenant.'.$page.'.invoices')->set('rowSearch', '12223')
        ->assertViewHas('productResults', fn ($results): bool => $results->isEmpty());
})->with(['sales', 'purchases']);

test('invoice search lists matching items from the highest to the lowest branch stock', function (string $page): void {
    $stocks = ['قهوة تركية قليلة' => 3, 'قهوة تركية كثيرة' => 90, 'قهوة تركية متوسطة' => 40];
    $ids = [];
    foreach ($stocks as $name => $stock) {
        $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'cost_price' => 1]);
        BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'stock_quantity' => $stock, 'retail_price' => 5]);
        $ids[$stock] = $product->id;
    }
    $noStock = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'قهوة تركية بلا فرع', 'cost_price' => 1]);

    Livewire::test('pages::tenant.'.$page.'.invoices')->set('rowSearch', 'قهوة تركية')
        ->assertViewHas('productResults', fn ($results): bool => $results->pluck('id')->all() === [$ids[90], $ids[40], $ids[3], $noStock->id]);
})->with(['sales', 'purchases']);
