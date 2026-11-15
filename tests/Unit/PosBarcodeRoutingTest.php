<?php

use Symfony\Component\Process\Process;

test('barcode keyboard listener targets the POS component and ignores other pages', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const template = fs.readFileSync('resources/views/pages/tenant/pos/partials/cart.blade.php', 'utf8');
    const script = fs.readFileSync('resources/js/pos-cart-keyboard.js', 'utf8');
    assert.ok(template.includes('wire:keydown.enter.prevent.stop="scanBarcode($event.target.value)"'));
    assert.ok(fs.readFileSync('resources/js/app.js', 'utf8').includes("import './pos-cart-keyboard.js'"));
    const listeners = new Map();
    const calls = [];
    const lookups = [];
    const unrelatedRoot = {getAttribute: () => 'tenant-switcher'};
    const unrelatedField = {value: '', closest: selector => selector.includes('wire') ? unrelatedRoot : null, dispatchEvent() {}};
    let input = {dataset: {posComponentId: 'pos-sales'}, disabled: false, value: '', focus() {}, select() {}};
    input.closest = selector => selector === '[data-pos-barcode-input]' ? input : unrelatedRoot;
    let missingBarcodeModalOpen = false;
    const document = {
        addEventListener: (name, callback) => listeners.set(name, [...(listeners.get(name) || []), callback]),
        querySelector: selector => selector === '[data-pos-missing-barcode-dialog]' ? (missingBarcodeModalOpen ? {} : null) : (selector === '[data-pos-barcode-input]' ? input : unrelatedRoot),
        querySelectorAll: () => [], contains: () => true,
    };
    const window = {
        addEventListener() {},
        Livewire: {find(id) {
            lookups.push(id);
            assert.ok(['pos-sales', 'pos-next'].includes(id), 'A barcode was routed to an unrelated component');
            return {$call: async (method, code) => calls.push([id, method, code])};
        }},
    };
    vm.runInNewContext(script, {window, document, Date, Map, Number, Array, console, Event: class {}});
    const listenerCount = listeners.get('keydown').length;
    vm.runInNewContext(script, {window, document, Date, Map, Number, Array, console, Event: class {}});
    assert.equal(listeners.get('keydown').length, listenerCount, 'Navigation installed duplicate barcode handlers');
    let prevented = 0;
    function key(key, target = unrelatedField) {
        let stopped = false;
        const event = {key, target, preventDefault() {prevented++}, stopImmediatePropagation() {stopped = true}, stopPropagation() {}};
        for (const callback of listeners.get('keydown')) {
            if (stopped) break;
            callback(event);
        }
        if (!stopped && key === 'Enter' && target === input) {
            window.Livewire.find(input.dataset.posComponentId).$call('scanBarcode', input.value);
        }
    }
    function scan(code) {for (const character of code) key(character); key('Enter');}
    (async () => {
        scan('123456');
        await new Promise(setImmediate);
        assert.deepEqual(calls, [['pos-sales', 'scanBarcode', '123456']]);
        input.value = 'DIRECT-123';
        key('Enter', input);
        await new Promise(setImmediate);
        assert.equal(calls[1][2], 'DIRECT-123');
        input.value = '6958326545504';
        key('Tab', input);
        await new Promise(setImmediate);
        assert.equal(calls[2][2], '6958326545504');
        const beforeEmptyTab = prevented;
        key('Tab', input);
        assert.equal(prevented, beforeEmptyTab, 'Empty Tab should retain normal focus navigation');
        missingBarcodeModalOpen = true;
        const beforeModalTyping = prevented;
        scan('SEARCH');
        await new Promise(setImmediate);
        assert.equal(calls.length, 3, 'Modal typing was treated as a barcode');
        assert.equal(prevented, beforeModalTyping, 'Modal search typing was intercepted');
        missingBarcodeModalOpen = false;
        input.dataset.posMobileSearch = 'true';
        let cancelledSearches = 0;
        input.dispatchEvent = () => cancelledSearches++;
        input.value = 'Coffee';
        key('Enter', input);
        await new Promise(setImmediate);
        assert.deepEqual(calls[3], ['pos-sales', 'submitMobileProductSearch', 'Coffee']);
        assert.equal(cancelledSearches, 1, 'Mobile submission did not cancel pending text search');
        input.value = '6958326545504';
        key('Enter', input);
        await new Promise(setImmediate);
        assert.deepEqual(calls[4], ['pos-sales', 'submitMobileProductSearch', '6958326545504']);
        for (const callback of listeners.get('livewire:navigating')) callback();
        input = null;
        prevented = 0;
        scan('654321');
        await new Promise(setImmediate);
        assert.equal(calls.length, 5);
        assert.equal(prevented, 0, 'Typing on another page was intercepted');
        input = {dataset: {posComponentId: 'pos-next'}, disabled: false, value: '', focus() {}, select() {}};
        scan('NEXT123');
        await new Promise(setImmediate);
        assert.deepEqual(calls[5], ['pos-next', 'scanBarcode', 'NEXT123']);
        console.log('BARCODE_ROUTING_OK');
    })().catch(error => {console.error(error); process.exitCode = 1});
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('BARCODE_ROUTING_OK');
});

test('a barcode scanned while the cursor is in a number field is added to the invoice, not typed into the amount', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const script = fs.readFileSync('resources/js/pos-cart-keyboard.js', 'utf8');
    const listeners = new Map();
    const calls = [];
    let clock = 1000;
    const barcodeInput = {dataset: {posComponentId: 'pos-sales'}, disabled: false, value: '', focus() {}, select() {}, closest: () => null};
    const discount = {value: '5', events: 0, dispatchEvent() {this.events++}};
    discount.closest = selector => selector.includes('input[type="number"]') ? discount : null;
    const document = {
        addEventListener: (name, callback) => listeners.set(name, [...(listeners.get(name) || []), callback]),
        querySelector: selector => selector === '[data-pos-barcode-input]' ? barcodeInput : null,
        querySelectorAll: () => [], contains: () => true,
    };
    const window = {addEventListener() {}, Livewire: {find: () => ({$call: async (method, code) => calls.push([method, code])})}};
    vm.runInNewContext(script, {window, document, Date: {now: () => clock}, Map, Number, Array, String, console, Event: class {}});
    function key(key, gap) {
        clock += gap;
        let stopped = false;
        let prevented = false;
        const event = {key, target: discount, preventDefault() {prevented = true}, stopImmediatePropagation() {stopped = true}};
        for (const callback of listeners.get('keydown')) {
            if (stopped) break;
            callback(event);
        }
        // The browser types the character unless the key was prevented.
        if (!prevented && key.length === 1) discount.value += key;
        return prevented;
    }
    (async () => {
        for (const character of '6958766523162') key(character, 8);
        assert.equal(key('Enter', 8), true, 'The scanner Enter must not reach the amount field');
        await new Promise(setImmediate);
        assert.deepEqual(calls, [['scanBarcode', '6958766523162']]);
        assert.equal(discount.value, '5', 'The amount must return to what it was before the scan');
        assert.equal(discount.events, 1);

        discount.value = '';
        for (const character of '125000') key(character, 180);
        assert.equal(key('Enter', 180), false, 'An amount typed by hand must stay in the field');
        await new Promise(setImmediate);
        assert.equal(calls.length, 1);
        assert.equal(discount.value, '125000');

        discount.value = '';
        for (const character of '12') key(character, 8);
        assert.equal(key('Enter', 8), false, 'A short fast entry is not a barcode');
        assert.equal(discount.value, '12');
        console.log('FIELD_SCAN_OK');
    })().catch(error => {console.error(error); process.exitCode = 1});
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('FIELD_SCAN_OK');
});

test('F7 removes the selected cart line and selects its neighbour', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    assert.ok(fs.readFileSync('resources/views/pages/tenant/pos/partials/cart.blade.php', 'utf8').includes('data-pos-remove-line'));
    const listeners = new Map();
    const events = [];
    const makeRow = name => {
        const button = {disabled: false, click: () => events.push('remove ' + name)};
        const row = {name, classList: {toggle() {}}, focus: () => events.push('focus ' + name), scrollIntoView() {},
            querySelector: selector => selector === '[data-pos-remove-line]' ? button : null, querySelectorAll: () => []};
        row.closest = selector => selector === '[data-pos-cart-line]' ? row : null;
        row.button = button;
        return row;
    };
    const rows = [makeRow('first'), makeRow('second')];
    const barcode = {dataset: {}, disabled: false, value: '', focus: () => events.push('focus barcode'), select() {}, closest: () => null};
    const document = {
        addEventListener: (name, callback) => listeners.set(name, [...(listeners.get(name) || []), callback]),
        querySelector: selector => selector === '[data-pos-barcode-input]' ? barcode : null,
        querySelectorAll: selector => selector.includes('data-pos-cart-line') ? rows : [], contains: () => true,
    };
    const window = {addEventListener() {}, requestAnimationFrame: callback => callback(), setTimeout: callback => callback()};
    vm.runInNewContext(fs.readFileSync('resources/js/pos-cart-keyboard.js', 'utf8'),
        {window, document, Date, Map, Number, Array, console, Event: class {}, requestAnimationFrame: callback => callback(), setTimeout: callback => callback()});
    function press(target) {
        let prevented = false;
        const event = {key: 'F7', target, repeat: false, preventDefault: () => { prevented = true; }, stopPropagation() {}};
        listeners.get('keydown').forEach(listener => listener(event));
        return prevented;
    }

    assert.equal(press(rows[0]), true);
    assert.deepEqual(events.slice(0, 2), ['remove first', 'focus second']);

    events.length = 0;
    rows[1].button.disabled = true;
    assert.equal(press(rows[1]), false, 'A locked invoice line was removed');
    assert.deepEqual(events, []);

    assert.equal(press(barcode), false, 'F7 acted without a selected line');
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
