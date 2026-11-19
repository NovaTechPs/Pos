<?php

use Symfony\Component\Process\Process;

test('enter advances fields without saving and preserves search and modified keys', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('fs'), vm = require('vm'), assert = require('assert/strict');
    const listeners = [];
    let focused = null;
    class Field {
        constructor(tag = 'input', attributes = {}) { this.tag = tag; this.attributes = attributes; }
        matches(selector) {
            if (selector === 'input, select, textarea') return ['input','select','textarea'].includes(this.tag);
            if (selector.includes('button[type="submit"]')) return this.tag === 'button';
            return Boolean(this.disabled || this.readonly || this.hidden);
        }
        closest(selector) {
            if (selector === 'form') return form;
            return this.inert && selector.includes('[inert]') ? {} : null;
        }
        getAttribute(name) { return this.attributes[name] ?? null; }
        getClientRects() { return this.hidden ? [] : [{}]; }
        focus() { focused = this; }
    }
    const name = new Field(), amount = new Field('input', {'data-enter-next':'[data-voucher-notes]'});
    const method = new Field('select'), notes = new Field('textarea'), save = new Field('button');
    const hidden = new Field(), readonly = new Field(), disabled = new Field(), inert = new Field();
    hidden.hidden = true; readonly.readonly = true; disabled.disabled = true; inert.inert = true;
    let action = 'save';
    const fields = [name, hidden, readonly, disabled, inert, amount, method, notes];
    const form = {getAttribute: () => action, querySelectorAll: () => fields, querySelector: () => notes, elements: [...fields, save]};
    vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), {
        HTMLElement: Field,
        document: {body: {classList: {contains: () => true}}, addEventListener: (type, callback) => listeners.push(callback)},
    });
    function press(target, extra = {}) {
        const event = {key:'Enter', target, prevented:false, preventDefault() { this.prevented = true; }, ...extra};
        listeners.forEach(fn => fn(event));
        return event;
    }
    assert.ok(press(name).prevented);
    assert.equal(focused, amount, 'skips hidden, readonly, disabled and inert fields');
    press(amount); assert.equal(focused, notes, 'amount goes directly to notes');
    press(notes); assert.equal(focused, save, 'last field focuses save without clicking');
    assert.equal(press(save).prevented, false, 'explicit Enter on save remains usable');
    for (const modifier of ['shiftKey','ctrlKey','altKey','metaKey','isComposing']) {
        assert.equal(press(notes, {[modifier]:true}).prevented, false);
    }
    action = 'searchVoucherArchive'; focused = null;
    assert.equal(press(name).prevented, false); assert.equal(focused, null);
    console.log('FORM_ENTER_OK');
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script, resource_path('js/prevent-enter-submit.js')]);
    $process->mustRun();
    expect($process->getOutput())->toContain('FORM_ENTER_OK');
});

test('compact styles preserve mobile and print sizing and vouchers use explicit note navigation', function (): void {
    expect(file_get_contents(resource_path('css/app.css')))->toContain("@import './compact.css';");
    expect(file_get_contents(resource_path('css/compact.css')))
        ->toContain('@media screen and (min-width: 768px)', '[data-workspace-context]', '[data-voucher-compact]');
    expect(file_get_contents(resource_path('views/pages/tenant/accounting/partials/voucher-form.blade.php')))
        ->toContain('data-enter-next="[data-voucher-notes]"', 'data-voucher-notes', 'await $wire.selectParty');
});
