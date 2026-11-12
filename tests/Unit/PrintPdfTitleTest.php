<?php

use Symfony\Component\Process\Process;

test('printing a pdf from a workspace tab names the file after the document in every window title', function (): void {
    $script = <<<'JS'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const source = fs.readFileSync(process.argv[1], 'utf8');
    const timers = [];
    const listeners = new Map();
    let printedTitles = null;
    const topWindow = { document: { title: 'WaslaPOS - لوحة التحكم' } };
    topWindow.parent = topWindow;
    const frame = { style: {}, setAttribute() {}, remove() {}, contentWindow: { focus() {}, print() { printedTitles = [document.title, topWindow.document.title]; } } };
    const document = {
        title: 'فاتورة مبيعات',
        querySelectorAll() { return []; },
        createElement() { return frame; },
        body: { appendChild() {} },
    };
    const window = {
        parent: topWindow,
        addEventListener(name, callback) { listeners.set(name, callback); },
        setTimeout(callback) { timers.push(callback); return timers.length; },
        open() {},
    };
    vm.runInNewContext(source, { window, document });

    window.printPdf('/dashboard/sale-invoices/5/pdf', 'ابو هاشم ياسين - SAL-1');
    assert.equal(frame.src, '/dashboard/sale-invoices/5/pdf?inline=1');
    frame.onload();
    timers.shift()();
    assert.deepEqual(printedTitles, ['ابو هاشم ياسين - SAL-1', 'ابو هاشم ياسين - SAL-1']);
    timers.forEach((timer) => timer());
    assert.equal(document.title, 'فاتورة مبيعات');
    assert.equal(topWindow.document.title, 'WaslaPOS - لوحة التحكم');
    console.log('PRINT_PDF_TITLE_OK');
    JS;

    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/js/print-pdf.js']);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('PRINT_PDF_TITLE_OK');
});

test('the desktop app opens the document pdf through its bridge instead of a hidden frame', function (): void {
    $script = <<<'JS'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const requests = [];
    let framesCreated = 0;
    const topWindow = { waslaDesktop: { printPdf: async (request) => { requests.push(request); return true; } } };
    const document = { querySelectorAll() { return []; }, createElement() { framesCreated++; return { style: {}, setAttribute() {} }; }, body: { appendChild() {} } };
    const window = { top: topWindow, location: { href: 'http://127.0.0.1:9000/dashboard/sale-invoices' }, addEventListener() {}, alert(message) { throw new Error(message); } };
    vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), { window, document, URL });
    window.printPdf('/dashboard/sale-invoices/5/pdf', 'ابو هاشم - SAL-1');
    assert.equal(JSON.stringify(requests), JSON.stringify([{ url: 'http://127.0.0.1:9000/dashboard/sale-invoices/5/pdf?inline=1', title: 'ابو هاشم - SAL-1' }]));
    assert.equal(framesCreated, 0);
    console.log('DESKTOP_PRINT_PDF_OK');
    JS;

    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/js/print-pdf.js']);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('DESKTOP_PRINT_PDF_OK');
});
