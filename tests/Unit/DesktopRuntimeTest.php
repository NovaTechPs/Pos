<?php

use Symfony\Component\Process\Process;

test('desktop runtime preserves local data and isolates production configuration', function (): void {
    $runtime = dirname(__DIR__, 2).'/resources/desktop/runtime.cjs';
    $script = <<<'JAVASCRIPT'
    const assert = require('node:assert/strict');
    const fs = require('node:fs/promises');
    const os = require('node:os');
    const path = require('node:path');
    const { initializeData, buildEnvironment, reservePort, isLocalNavigation } = require(process.argv[1]);
    (async () => {
        const root = await fs.mkdtemp(path.join(os.tmpdir(), 'waslapos-runtime-'));
        const first = await initializeData(root);
        assert.match(first.key, /^base64:/);
        await fs.writeFile(first.database, 'existing invoices');
        const second = await initializeData(root);
        assert.equal(first.key, second.key);
        assert.equal(await fs.readFile(second.database, 'utf8'), 'existing invoices');
        const env = buildEnvironment({ SystemRoot: 'C:/Windows', Path: 'safe', APP_KEY: 'production-secret',
            DB_CONNECTION: 'mysql', DB_DATABASE: 'live', DB_PASSWORD: 'secret', DB_URL: 'mysql://production',
            BRANCH_SYNC_TOKEN: 'production-token', SESSION_DOMAIN: '.waslapos.app' }, root, 'backend', 9000, first.key);
        assert.equal(env.DB_CONNECTION, 'sqlite');
        assert.equal(env.DB_DATABASE, first.database);
        assert.equal(env.APP_KEY, first.key);
        assert.equal(env.APP_URL, 'http://127.0.0.1:9000');
        assert.equal(env.BRANCH_SYNC_MODE, 'edge');
        assert.equal(env.BRANCH_SYNC_SERVER_URL, 'https://waslapos.app');
        assert.equal(env.DB_PASSWORD, undefined);
        assert.equal(env.DB_URL, undefined);
        assert.equal(env.BRANCH_SYNC_TOKEN, undefined);
        assert.equal(env.APP_DEBUG, 'false');
        assert.equal(env.SESSION_DOMAIN, 'null');
        assert.ok(isLocalNavigation('http://127.0.0.1:9000/dashboard/pos', env.APP_URL));
        assert.ok(!isLocalNavigation('https://waslapos.app', env.APP_URL));
        assert.ok(!isLocalNavigation('http://127.0.0.1:9001', env.APP_URL));
        assert.ok(!isLocalNavigation('javascript:alert(1)', env.APP_URL));
        assert.ok((await reservePort()) > 0);
        assert.ok(path.resolve(root).startsWith(path.resolve(os.tmpdir()) + path.sep));
        await fs.rm(root, { recursive: true, force: true });
        console.log('DESKTOP_RUNTIME_OK');
    })().catch(error => { console.error(error); process.exitCode = 1; });
    JAVASCRIPT;
    $process = new Process(['node', '-e', $script, $runtime]);
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('DESKTOP_RUNTIME_OK');
});
