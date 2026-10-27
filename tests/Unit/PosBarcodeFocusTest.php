<?php

use Symfony\Component\Process\Process;

test('adding products restores barcode focus without changing the mobile page scroll position', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const source = fs.readFileSync('resources/views/pages/tenant/pos/⚡sales.blade.php', 'utf8');
    const offset = source.indexOf("const PANEL_SELECTOR = '[data-pos-products-panel]'");
    const code = source.slice(source.lastIndexOf('<script>', offset) + 8, source.indexOf('</script>', offset));
    let scrollY = 320;
    let focusCount = 0;
    let selectCount = 0;
    let coarsePointer = false;
    let barcode = {
        focus(options) { focusCount++; if (!options?.preventScroll) scrollY = 900; },
        select() { selectCount++; }
    };
    const windowListeners = new Map();
    const context = {
        window: {addEventListener(name, fn) {windowListeners.set(name, fn);}, matchMedia() {return {matches: coarsePointer};}},
        document: {addEventListener() {}, querySelector() {return barcode;}},
        setTimeout(fn) {fn();}
    };
    vm.runInNewContext(code, context);
    windowListeners.get('pos-focus-barcode')();
    windowListeners.get('pos-focus-barcode')();
    assert.equal(scrollY, 320, 'Adding a product scrolled the page to the barcode input');
    assert.equal(focusCount, 2);
    assert.equal(selectCount, 2);
    coarsePointer = true;
    windowListeners.get('pos-focus-barcode')();
    assert.equal(focusCount, 2, 'Touchscreen focus must stay on the chosen product');
    coarsePointer = false;
    barcode = null;
    windowListeners.get('pos-focus-barcode')();
    assert.equal(focusCount, 2, 'Focus attempted after leaving POS');
    barcode = {focus(options) {if (!options?.preventScroll) scrollY = 900;}, select() {}};
    const init = source.match(/x-init="(\$nextTick[^"\n]+)"/)[1];
    vm.runInNewContext(init, {window: context.window, $nextTick: fn => fn(), $el: {querySelector() {return barcode;}}});
    assert.equal(scrollY, 320, 'Mounting POS scrolled past the products');
    console.log('BARCODE_FOCUS_OK');
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();
    expect($process->getErrorOutput())->toBe('');
    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('BARCODE_FOCUS_OK');
});
