<?php

use Symfony\Component\Process\Process;

test('desktop opens only central server pages and detects lost connections', function (): void {
    $script = <<<'JAVASCRIPT'
    const assert = require('node:assert/strict');
    const { centralUrl, isCentralNavigation, centralReachable, isConnectionFailure } = require(process.argv[1]);
    (async () => {
        const server = 'https://waslapos.app';
        assert.equal(centralUrl(server, '/dashboard/purchase-invoices?search=1'), 'https://waslapos.app/dashboard/purchase-invoices?search=1');
        for (const target of ['//evil.test/x', 'https://evil.test/', 'dashboard', '/' + String.fromCharCode(92) + 'evil.test', 42]) {
            assert.throws(() => centralUrl(server, target), /غير صالحة/);
        }
        assert.throws(() => centralUrl('http://waslapos.app', '/dashboard'), /غير آمن/);
        assert.ok(isCentralNavigation('https://waslapos.app/login', server));
        assert.ok(!isCentralNavigation('https://waslapos.app.evil.test/', server));
        assert.ok(!isCentralNavigation('http://127.0.0.1:9000/dashboard', server));
        assert.equal(await centralReachable(async (url) => ({ ok: url === 'https://waslapos.app/up' }), server), true);
        assert.equal(await centralReachable(async () => { throw new Error('offline'); }, server), false);
        assert.ok(isConnectionFailure(-106) && isConnectionFailure(-105) && isConnectionFailure(-118));
        assert.ok(!isConnectionFailure(-3));
        console.log('DESKTOP_CENTRAL_OK');
    })().catch(error => { console.error(error); process.exitCode = 1; });
    JAVASCRIPT;
    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/desktop/central.cjs']);
    $process->mustRun();
    expect($process->getOutput())->toContain('DESKTOP_CENTRAL_OK');
});
