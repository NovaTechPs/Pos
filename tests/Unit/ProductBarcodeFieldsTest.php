<?php

use Symfony\Component\Process\Process;

test('barcodes scanned while a new field is on its way go into that field, not the previous one', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    assert.ok(fs.readFileSync('resources/js/app.js', 'utf8').includes("import './product-barcode-fields.js'"));
    const window = {};
    const timers = [];
    vm.runInNewContext(fs.readFileSync('resources/js/product-barcode-fields.js', 'utf8'),
        {window, Promise, String, Event: class {}, setTimeout: callback => timers.push(callback)});

    let focused = null;
    const makeInput = () => {
        const input = {value: '', events: 0, scrollIntoView() {}, focus() { focused = input; }, dispatchEvent() { input.events++; }};
        return input;
    };
    const inputs = [makeInput()];
    let requests = 0;
    const fields = window.productBarcodeFields({addBarcodeField: () => { requests++; }});
    fields.$root = {querySelectorAll: () => inputs};
    const press = (key, target = focused) => {
        let prevented = false;
        fields.onKey({key, target, preventDefault: () => { prevented = true; }});
        if (!prevented && key.length === 1) target.value += key;
        return prevented;
    };
    const scan = barcode => { [...barcode].forEach(key => press(key)); press('Enter'); };
    // The server answers a request for a new field: the field appears and is focused.
    const serverAddsField = () => { inputs.push(makeInput()); fields.focusBarcode(inputs.length - 1); };

    focused = inputs[0];
    scan('111');
    assert.equal(requests, 1, 'The first scan did not ask for a new field');

    // Two more barcodes arrive before the server has answered.
    scan('222');
    scan('333');
    assert.equal(inputs[0].value, '111', 'Later scans piled up in the first field');

    serverAddsField();
    assert.equal(inputs[1].value, '222');
    assert.ok(inputs[1].events > 0, 'The held barcode was not reported to Livewire');
    assert.equal(requests, 2, 'The held scan did not ask for the next field');
    assert.equal(inputs[0].value, '111');

    serverAddsField();
    assert.equal(inputs[2].value, '333');
    assert.equal(requests, 3);

    serverAddsField();
    assert.equal(focused, inputs[3]);
    assert.equal(inputs[3].value, '');

    // With an empty field already there, a scan moves to it at once without asking the server.
    scan('444');
    assert.equal(inputs[3].value, '444');
    assert.equal(requests, 4);
    serverAddsField();
    inputs.push(makeInput());
    focused = inputs[4];
    scan('555');
    assert.equal(focused, inputs[5]);
    assert.equal(requests, 4);

    // Typing by hand in a field is left alone, and Enter in an empty field does nothing.
    assert.equal(press('9'), false);
    inputs[5].value = '';
    press('Enter');
    assert.equal(requests, 4);
    console.log('PRODUCT_BARCODE_FIELDS_OK');
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->getOutput())->toContain('PRODUCT_BARCODE_FIELDS_OK');
});
