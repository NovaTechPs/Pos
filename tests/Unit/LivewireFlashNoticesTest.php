<?php

use Symfony\Component\Process\Process;

test('flash notices show once for five seconds and can appear again after expiry', function (): void {
    $script = <<<'JS'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const source = fs.readFileSync(process.argv[1], 'utf8');
    const timers = [];
    const listeners = new Map();
    const toasts = [];
    const window = {
        addEventListener(name, callback) { listeners.set(name, callback); },
        setTimeout(callback, delay) { timers.push({ callback, delay }); return timers.length; },
    };
    const document = {
        addEventListener() {},
        querySelectorAll() { return []; },
        createElement() {
            return {
                attributes: {}, classList: { add() {} },
                setAttribute(name, value) { this.attributes[name] = value; },
                remove() { this.removed = true; },
            };
        },
        body: { appendChild(toast) { toasts.push(toast); } },
    };
    class MutationObserver { observe() {} }
    vm.runInNewContext(source, { window, document, MutationObserver });

    const notify = listeners.get('app-flash-notice');
    const notifyToast = listeners.get('toast');
    notify({ detail: { message: 'تم الحفظ', variant: 'success' } });
    notify({ detail: { message: 'تم الحفظ', variant: 'success' } });
    assert.equal(toasts.length, 1, 'Repeated identical notices must be deduplicated');
    assert.equal(toasts[0].attributes.role, 'status');
    notifyToast({ detail: { message: 'تم حفظ السند', type: 'success' } });
    assert.equal(toasts.length, 2, 'Legacy toast events must display a notice');
    assert.equal(toasts[1].attributes.role, 'status');
    assert.equal(timers.filter(timer => timer.delay === 5000).length, 4, 'Dedupe and hide timers must both use five seconds for each notice');

    for (const timer of timers.filter(timer => timer.delay === 5000)) timer.callback();
    notify({ detail: { message: 'تم الحفظ', variant: 'success' } });
    assert.equal(toasts.length, 3, 'The notice may appear again after the five-second window expires');
    console.log('FLASH_NOTICES_OK');
    JS;

    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/js/livewire-flash-notices.js']);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('FLASH_NOTICES_OK');
});
