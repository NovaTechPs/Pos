<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

test('search lists across the app opt into arrow-key navigation', function (): void {
    $root = resource_path('views/pages/tenant/');

    foreach ([
        'wholesale/⚡sales.blade.php' => 3,
        'returns/⚡index.blade.php' => 1,
        'stock-transfers/⚡index.blade.php' => 2,
        'pos/partials/cash-return-modal.blade.php' => 1,
    ] as $file => $containers) {
        $source = file_get_contents($root.$file);

        expect(substr_count($source, 'data-search-nav '))->toBe($containers, $file)
            ->and($source)->toContain('data-search-option');
    }

    foreach ([
        'pos/partials/payment.blade.php',
        'pos/partials/missing-barcode-modal.blade.php',
        'customer-visits/⚡index.blade.php',
        'offers/⚡index.blade.php',
        'reports/⚡center.blade.php',
    ] as $file) {
        expect(file_get_contents($root.$file))->toContain('data-search-option');
    }

    foreach ([
        'products/⚡index.blade.php',
        'employees/⚡index.blade.php',
        'branches/⚡index.blade.php',
        'categories/⚡index.blade.php',
        'roles/⚡index.blade.php',
        'stores/⚡index.blade.php',
        'stores/⚡online.blade.php',
        'wholesale/⚡invoices.blade.php',
        'stock-transfers/⚡index.blade.php',
        'stock-vouchers/⚡index.blade.php',
        'hr/⚡tasks.blade.php',
        'hr/⚡payroll-manager.blade.php',
        'system/⚡logs.blade.php',
        'accounting/partials/voucher-table.blade.php',
    ] as $file) {
        expect(file_get_contents($root.$file))->toContain('data-search-nav', 'data-search-input', 'data-search-option');
    }

    expect(file_get_contents(resource_path('js/app.js')))->toContain("import './search-keyboard.js';")
        ->and(file_get_contents(resource_path('css/app.css')))->toContain('body [data-search-option]:focus *', 'background-color: #123b86 !important;');
});

test('arrow keys highlight search results in dark blue and enter picks the highlighted one', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('fs');
    const vm = require('vm');
    const assert = require('assert/strict');
    const source = fs.readFileSync('resources/js/search-keyboard.js', 'utf8').replaceAll('export function', 'function');
    const listeners = {};
    class FakeElement {
        constructor(kind) { this.kind = kind; this.classes = new Set(); this.attributes = {}; this.clicks = 0; this.hidden = false; }
        get classList() { const classes = this.classes; return { contains: (name) => classes.has(name), toggle: (name, on) => on ? classes.add(name) : classes.delete(name) }; }
        getClientRects() { return this.hidden ? [] : [{}]; }
        closest(selector) {
            if (selector === '[data-search-nav]') return container;
            if (selector === '[data-search-option]') return this.kind === 'option' ? this : null;
            return null;
        }
        matches(selector) {
            if (selector.startsWith('input')) return this.kind === 'input';
            if (selector.startsWith('a, button')) return this.kind === 'option';
            return false;
        }
        setAttribute(name, value) { this.attributes[name] = value; }
        removeAttribute(name) { delete this.attributes[name]; }
        hasAttribute(name) { return name in this.attributes; }
        scrollIntoView() {}
        focus() { focused = this; }
        click() { this.clicks++; }
    }
    let focused = null;
    const input = new FakeElement('input');
    const options = [new FakeElement('option'), new FakeElement('option'), new FakeElement('option')];
    const container = { querySelector: () => null, querySelectorAll: (selector) => selector === '[data-search-option]' ? options : [input] };
    vm.runInNewContext(source, {
        document: { addEventListener: (type, fn) => { listeners[type] = fn; }, querySelectorAll: () => [container], documentElement: {} },
        HTMLElement: FakeElement, Element: FakeElement, WeakMap,
        MutationObserver: class { observe() {} },
    });
    const press = (target, key) => {
        const event = { key, target, defaultPrevented: false, stopped: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; }, stopPropagation() {} };
        listeners.keydown(event);
        return event;
    };
    const highlighted = () => options.map((option) => option.classes.has('search-result-active'));

    assert.equal(press(input, 'Enter').defaultPrevented, false, 'Enter without a highlight keeps the search box behaviour');
    assert.ok(press(input, 'ArrowDown').defaultPrevented);
    assert.deepEqual(highlighted(), [true, false, false]);
    press(input, 'ArrowDown');
    press(input, 'ArrowDown');
    press(input, 'ArrowDown');
    assert.deepEqual(highlighted(), [false, false, true], 'ArrowDown stops at the last result');
    press(input, 'ArrowUp');
    assert.deepEqual(highlighted(), [false, true, false]);

    const enter = press(input, 'Enter');
    assert.ok(enter.defaultPrevented && enter.stopped, 'Enter on a highlight replaces the search box action');
    assert.deepEqual(options.map((option) => option.clicks), [0, 1, 0]);

    options[1].hidden = true;
    press(input, 'Home');
    assert.deepEqual(highlighted(), [true, false, false]);
    press(input, 'ArrowDown');
    assert.deepEqual(highlighted(), [false, false, true], 'hidden results are skipped');
    options[1].hidden = false;

    listeners.input({ target: input });
    assert.deepEqual(highlighted(), [false, false, false], 'typing clears the highlight');

    press(input, 'ArrowDown');
    press(input, 'ArrowUp');
    assert.deepEqual(highlighted(), [false, false, false], 'ArrowUp from the first result returns to the search box');

    press(options[0], 'ArrowDown');
    assert.equal(focused, options[1], 'a focused result moves focus to the next one');
    press(options[0], 'ArrowUp');
    assert.equal(focused, input, 'ArrowUp from the first focused result returns to the search box');
    press(options[2], 'Enter');
    assert.equal(options[2].clicks, 1);

    const row = new FakeElement('row');
    const openButton = new FakeElement('option');
    const deleteButton = new FakeElement('option');
    row.querySelector = (selector) => selector === '[data-search-open]' ? openButton : deleteButton;
    options.splice(0, options.length, row);
    press(input, 'ArrowDown');
    press(input, 'Enter');
    assert.equal(openButton.clicks, 1, 'a table row opens through its [data-search-open] control');
    assert.equal(deleteButton.clicks, 0);

    const otherField = new FakeElement('input');
    container.querySelector = () => ({});
    input.closest = (selector) => selector === '[data-search-input]' ? {} : FakeElement.prototype.closest.call(input, selector);
    options.splice(0, options.length, new FakeElement('option'));
    listeners.input({ target: input });
    assert.equal(press(otherField, 'ArrowDown').defaultPrevented, false, 'other fields of the page keep their arrow keys');
    assert.ok(press(input, 'ArrowDown').defaultPrevented, 'the marked search box drives the list');
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], base_path());
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->isSuccessful())->toBeTrue();
});

test('flux search boxes and table rows render the navigation markers', function (): void {
    $tenant = Tenant::create(['name' => 'متجر البحث']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع الرئيسي']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);

    $html = Livewire::test('pages::tenant.branches.index')->html();

    expect($html)->toMatch('/<input[^>]*data-search-input/')
        ->toMatch('/<tr[^>]*data-search-option/')
        ->toMatch('/<button[^>]*data-search-open[^>]*wire:click="edit\(/s');
});
