<?php

use Symfony\Component\Process\Process;

test('arrow navigation moves the shared highlight between search results', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('node:fs');
    const assert = require('node:assert/strict');
    const root = 'resources/views/pages/tenant/';
    function method(source, name) {
        const match = source.match(new RegExp('        ' + name + '\\([^]*?\\n        },'));
        assert.ok(match, name);
        return Function('return ({' + match[0] + '})')()[name];
    }
    function rows() {
        return Array.from({length: 3}, () => ({
            classes: new Set(),
            classList: {toggle(name, enabled) { enabled ? this.owner.classes.add(name) : this.owner.classes.delete(name); }},
            scrollIntoView() {},
        })).map(row => { row.classList.owner = row; return row; });
    }
    function selected(items, index) {
        assert.deepEqual(items.map(row => row.classes.has('search-result-active')), items.map((_, i) => i === index));
    }
    const sales = fs.readFileSync(root + 'sales/⚡invoices.blade.php', 'utf8');
    for (const type of ['product', 'customer']) {
        const items = rows();
        const component = {
            productIndex: -1, customerIndex: -1,
            productResults: () => type === 'product' ? items : [],
            customerResults: () => type === 'customer' ? items : [],
            refreshResultClasses: method(sales, 'refreshResultClasses'),
            moveResult: method(sales, 'moveResult'),
        };
        component.moveResult(type, 1); selected(items, 0);
        component.moveResult(type, 1); selected(items, 1);
        component.moveResult(type, -1); selected(items, 0);
    }
    const purchases = fs.readFileSync(root + 'purchases/⚡invoices.blade.php', 'utf8');
    for (const type of ['Product', 'Supplier']) {
        const items = rows();
        const component = {productIndex: -1, supplierIndex: -1, visibleResults: () => items};
        const move = method(purchases, 'move' + type).bind(component);
        move(1); selected(items, 0);
        move(1); selected(items, 1);
        move(-1); selected(items, 0);
        move(-1); selected(items, 2);
    }
    for (const file of ['returns/⚡index.blade.php', 'reports/⚡center.blade.php', 'products/⚡printer.blade.php', 'accounting/partials/voucher-form.blade.php']) {
        const source = fs.readFileSync(root + file, 'utf8');
        const binding = source.match(/x-bind:class="([^"]*search-result-active[^"]*)"/)[1]
            .replaceAll('{{ $loop->index }}', '1');
        const evaluate = Function('active', 'activeIndex', 'results', '$el', 'return ' + binding);
        const element = {};
        assert.ok(evaluate(1, 1, () => [{}, element], element).includes('search-result-active'), file);
        assert.ok(!evaluate(0, 0, () => [{}, element], element).includes('search-result-active'), file);
    }
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->isSuccessful())->toBeTrue();
});

test('search highlights use dark blue with white text including focused results and nested labels', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

    expect($css)
        ->toContain('body .search-result-active *', 'body [data-pos-product]:focus *', 'body [data-stock-voucher-product-result]:focus *')
        ->toContain('background-color: #1e40af !important;', 'color: #ffffff !important;');
});
