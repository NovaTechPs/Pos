<?php

use Symfony\Component\Process\Process;

test('login feedback appears immediately blocks duplicates and resets when returning to the page', function (): void {
    $script = <<<'JS'
    const assert = require('node:assert/strict');
    (async () => {
        const { bindLoginProgress } = await import(process.argv[1]);
        const elements = new Map(['[type="submit"]','[data-login-label]','[data-login-pending]','[data-login-status]'].map(key => [key, { hidden: key.includes('pending') || key.includes('status'), disabled: false }]));
        const handlers = {}, timers = new Map(); let next = 0;
        const form = { dataset: {}, querySelector: selector => elements.get(selector), addEventListener: (name, handler) => handlers[name] = handler,
            setAttribute: (key, value) => form[key] = value, removeAttribute: key => delete form[key] };
        const view = { setTimeout: handler => { timers.set(++next, handler); return next; }, clearTimeout: id => timers.delete(id), addEventListener: (key, handler) => handlers[key] = handler };
        bindLoginProgress(form, view);
        let prevented = 0;
        handlers.submit({ preventDefault: () => prevented++ });
        assert.equal(prevented, 0);
        assert.equal(elements.get('[type="submit"]').disabled, true);
        assert.equal(elements.get('[data-login-pending]').hidden, false);
        assert.equal(form['aria-busy'], 'true');
        handlers.submit({ preventDefault: () => prevented++ });
        assert.equal(prevented, 1);
        assert.equal(timers.size, 1);
        timers.get(1)();
        assert.ok(elements.get('[data-login-status]').textContent.includes('قيد الانتظار'));
        handlers.pageshow();
        assert.equal(elements.get('[type="submit"]').disabled, false);
        assert.equal(elements.get('[data-login-label]').hidden, false);
        assert.equal(timers.size, 0);
        handlers.submit({ preventDefault: () => prevented++ });
        assert.equal(prevented, 1);
        console.log('LOGIN_PROGRESS_OK');
    })().catch(error => { console.error(error); process.exitCode = 1; });
    JS;
    $process = new Process(['node', '-e', $script, 'file:///'.str_replace('\\', '/', dirname(__DIR__, 2).'/resources/js/login-progress.js')]);
    $process->mustRun();
    expect($process->getOutput())->toContain('LOGIN_PROGRESS_OK');
});
