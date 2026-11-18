<?php

use Symfony\Component\Process\Process;

test('label preview and print fit the complete barcode and preserve quiet zones', function (): void {
    $script = <<<'JS'
    const assert = require('node:assert/strict');
    const fs = require('node:fs');
    const vm = require('node:vm');
    const blade = fs.readFileSync(process.argv[1], 'utf8');
    const source = blade.match(/@script\s*<script>([\s\S]*?)<\/script>/)[1];
    const makeSvg = () => ({ attrs: {}, style: {}, setAttribute(key, value) { this.attrs[key] = value; } });
    const preview = makeSvg();
    const renders = [];
    let printedHtml = '';
    const document = {
        getElementById(id) {
            if (id === 'preview-barcode-wrapper') return { dataset: { barcode: '0000000000319' } };
            if (id === 'preview-barcode-element') return preview;
            return null;
        },
        createElementNS: makeSvg,
    };
    const window = {
        JsBarcode(svg, value, options) {
            svg.style = { transform: 'translate(0,0)' };
            svg.setAttribute('viewBox', '0 0 240 78');
            renders.push({ svg, value, options });
        },
        open() {
            return { document: { open() {}, write(html) { printedHtml = html; }, close() {}, readyState: 'loading' }, addEventListener() {} };
        },
    };
    const context = { window, document, Livewire: { on() {}, hook() {} }, console, setTimeout, clearTimeout,
        alert(message) { throw new Error(message); },
        XMLSerializer: class { serializeToString(svg) { return `<svg viewBox="${svg.attrs.viewBox}" preserveAspectRatio="${svg.attrs.preserveAspectRatio}"></svg>`; } },
    };
    vm.runInNewContext(source, context);
    (async () => {
        await window.labelPrinter.print({ barcode: '0000000000319', w: 50, h: 25, name: 'Product', price: '35' });
        assert.equal(renders.length, 2);
        assert.equal(preview.style.height, 'auto');
        assert.equal(renders[1].svg.style.height, '100%');
        for (const { svg, value, options } of renders) {
            assert.equal(value, '0000000000319');
            assert.ok(options.marginLeft >= options.width * 10);
            assert.ok(options.marginRight >= options.width * 10);
            assert.equal(svg.attrs.viewBox, '0 0 240 78');
            assert.equal(svg.attrs.preserveAspectRatio, 'xMidYMid meet');
            assert.equal(svg.style.width, '100%');
            assert.equal(svg.style.maxWidth, '100%');
            assert.equal(svg.style.direction, 'ltr');
        }
        assert.ok(printedHtml.includes('preserveAspectRatio="xMidYMid meet"'));
        assert.ok(printedHtml.includes('size: 50mm 25mm'));
    })().catch(error => { console.error(error); process.exitCode = 1; });
    JS;

    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/views/pages/tenant/products/⚡printer.blade.php']);
    $process->mustRun();

    expect($process->isSuccessful())->toBeTrue();
});
