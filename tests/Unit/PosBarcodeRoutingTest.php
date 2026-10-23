<?php

use Symfony\Component\Process\Process;

test('barcode keyboard listener targets the POS component and ignores other pages', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const template = fs.readFileSync('resources/views/pages/tenant/pos/partials/cart.blade.php', 'utf8');
    const script = [...template.matchAll(/<script>([\s\S]*?)<\/script>/g)].find(match => match[1].includes('barcodeQueue'))[1];
    const listeners = new Map();
    const calls = [];
    const lookups = [];
    const unrelatedRoot = {getAttribute: () => 'tenant-switcher'};
    const unrelatedField = {value: '', closest: selector => selector.includes('wire') ? unrelatedRoot : null, dispatchEvent() {}};
    let input = {dataset: {posComponentId: 'pos-sales'}, disabled: false, value: '', focus() {}, select() {}};
    input.closest = selector => selector === '[data-pos-barcode-input]' ? input : unrelatedRoot;
    const document = {
        addEventListener: (name, callback) => listeners.set(name, [...(listeners.get(name) || []), callback]),
        querySelector: selector => selector === '[data-pos-barcode-input]' ? input : unrelatedRoot,
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
    let prevented = 0;
    function key(key, target = unrelatedField) {
        const event = {key, target, preventDefault() {prevented++}, stopImmediatePropagation() {}, stopPropagation() {}};
        for (const callback of listeners.get('keydown')) callback(event);
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
        for (const callback of listeners.get('livewire:navigating')) callback();
        input = null;
        prevented = 0;
        scan('654321');
        await new Promise(setImmediate);
        assert.equal(calls.length, 2);
        assert.equal(prevented, 0, 'Typing on another page was intercepted');
        input = {dataset: {posComponentId: 'pos-next'}, disabled: false, value: '', focus() {}, select() {}};
        scan('NEXT123');
        await new Promise(setImmediate);
        assert.deepEqual(calls[2], ['pos-next', 'scanBarcode', 'NEXT123']);
        console.log('BARCODE_ROUTING_OK');
    })().catch(error => {console.error(error); process.exitCode = 1});
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('BARCODE_ROUTING_OK');
});
