<?php

use Symfony\Component\Process\Process;

test('POS saving immediately blocks repeated actions and unlocks after completion', function (): void {
    $script = <<<'JS'
    const assert = require('node:assert/strict');
    (async () => {
        const { createPosSaveInterceptor } = await import(process.argv[1]);
        const intercept = createPosSaveInterceptor();
        const overlay = { style: { display: 'none' } };
        const root = { matches: () => true, querySelector: () => overlay,
            setAttribute: (key, value) => root[key] = value, removeAttribute: key => delete root[key] };
        let canceled = 0, finish;
        const call = name => intercept({ action: { name, component: { el: root }, cancel: () => canceled++ }, onFinish: callback => finish = callback });
        call('scanBarcode');
        assert.equal(overlay.style.display, 'none');
        for (const name of ['checkout', 'checkoutAndPrint', 'saveOrEditInvoice', 'confirmCustomerPayment', 'confirmBelowCostCheckout', 'sendInvoiceWhatsApp', 'downloadInvoicePdf']) {
            call(name);
            assert.equal(overlay.style.display, 'flex');
            assert.equal(root['aria-busy'], 'true');
            const originalFinish = finish, before = canceled;
            call(name); call('clearCart'); call('refreshLiveData');
            assert.equal(canceled, before + 3);
            assert.equal(finish, originalFinish);
            finish();
            assert.equal(overlay.style.display, 'none');
            assert.equal(root['aria-busy'], undefined);
        }
        intercept({ action: { name: 'checkout', component: { el: { matches: () => false } } } });
        console.log('POS_SAVE_PROGRESS_OK');
    })().catch(error => { console.error(error); process.exitCode = 1; });
    JS;
    $process = new Process(['node', '-e', $script, 'file:///'.str_replace('\\', '/', dirname(__DIR__, 2).'/resources/js/pos-save-progress.js')]);
    $process->mustRun();
    expect($process->getOutput())->toContain('POS_SAVE_PROGRESS_OK');
});
