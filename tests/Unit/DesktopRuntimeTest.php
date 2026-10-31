<?php

use Symfony\Component\Process\Process;

test('bundled PHP caches compiled code while detecting updated application files', function (): void {
    $root = dirname(__DIR__, 2).'/storage/app/desktop-build/stage/php';
    $storage = sys_get_temp_dir().'/wasla-opcache-'.bin2hex(random_bytes(6));
    mkdir($storage.'/framework/cache/php', 0777, true);
    $process = new Process([$root.'/php.exe', '-c', $root.'/php.ini', '-r',
        'echo json_encode(opcache_get_configuration()["directives"]);'], dirname($root).'/backend', ['APP_STORAGE_PATH' => $storage]);
    $process->mustRun();
    $settings = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($settings['opcache.enable'])->toBeTrue()
        ->and($settings['opcache.enable_cli'])->toBeTrue()
        ->and($settings['opcache.validate_timestamps'])->toBeTrue()
        ->and($settings['opcache.revalidate_freq'])->toBe(0)
        ->and($settings['opcache.max_accelerated_files'])->toBeGreaterThanOrEqual(20000);
    expect($settings['opcache.file_cache'])->toBe($storage.'/framework/cache/php')
        ->and($settings['opcache.file_cache_fallback'])->toBeTrue();
})->skip(PHP_OS_FAMILY !== 'Windows' || ! is_file(dirname(__DIR__, 2).'/storage/app/desktop-build/stage/php/php.exe'));

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

test('desktop synchronization coalesces requests and never overlaps upload and catalog download', function (): void {
    $script = <<<'JS'
    const assert = require('node:assert/strict');
    const { createSyncRunner } = require(process.argv[1]);
    (async () => {
        let release; let uploads = 0, downloads = 0, active = 0, peak = 0;
        const run = createSyncRunner(async () => { active++; peak = Math.max(peak, active); uploads++; if (uploads === 1) await new Promise(resolve => release = resolve); active--; },
            async () => { active++; peak = Math.max(peak, active); downloads++; active--; }, error => { throw error; });
        const first = run();
        const second = run(true);
        assert.equal(first, second);
        run(true);
        release();
        await first;
        assert.equal(peak, 1);
        assert.equal(downloads, 1);
        await run(true);
        assert.equal(downloads, 2);
        console.log('DESKTOP_SYNC_SERIAL_OK');
    })().catch(error => { console.error(error); process.exitCode = 1; });
    JS;
    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/desktop/runtime.cjs']);
    $process->mustRun();
    expect($process->getOutput())->toContain('DESKTOP_SYNC_SERIAL_OK');
});

test('desktop checks migrations once per release and retries failed or reset databases', function (): void {
    $script = <<<'JS'
    const assert = require('node:assert/strict');
    const fs = require('node:fs/promises'), path = require('node:path'), os = require('node:os');
    const { initializeData, ensureMigrations } = require(process.argv[1]);
    (async () => {
        const root = await fs.mkdtemp(path.join(os.tmpdir(), 'wasla-migration-'));
        try {
            const { database } = await initializeData(root);
            let runs = 0;
            const migrate = async () => { runs++; await fs.writeFile(database, 'test database'); };
            assert.equal(await ensureMigrations(root, database, '1', migrate), true);
            assert.equal(await ensureMigrations(root, database, '1', migrate), false);
            assert.equal(runs, 1);
            await assert.rejects(ensureMigrations(root, database, '2', async () => { throw Error('failed'); }));
            assert.equal(await ensureMigrations(root, database, '2', migrate), true);
            assert.equal(runs, 2);
            await fs.writeFile(database, '');
            assert.equal(await ensureMigrations(root, database, '2', migrate), true);
            await fs.writeFile(path.join(root, 'migration-state.json'), '{broken');
            assert.equal(await ensureMigrations(root, database, '2', migrate), true);
            const replacement = path.join(root, 'replacement.sqlite');
            await fs.writeFile(replacement, 'replacement database');
            await fs.unlink(database);
            await fs.rename(replacement, database);
            assert.equal(await ensureMigrations(root, database, '2', migrate), true);
            assert.equal(runs, 5);
            console.log('DESKTOP_MIGRATIONS_OK');
        } finally { await fs.rm(root, { recursive: true, force: true }); }
    })().catch(error => { console.error(error); process.exitCode = 1; });
    JS;
    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/desktop/runtime.cjs']);
    $process->mustRun();
    expect($process->getOutput())->toContain('DESKTOP_MIGRATIONS_OK');
});
