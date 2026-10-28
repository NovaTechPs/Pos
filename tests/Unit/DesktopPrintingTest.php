<?php

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

test('desktop printer preferences are independent per page and keep the previous default', function (): void {
    $script = <<<'JAVASCRIPT'
    const assert = require('node:assert/strict');
    const { pageKey, printerForPage, printerMenu, printSnapshot } = require(process.argv[1]);
    (async () => {
        const preferences = { printer: 'A4', pagePrinters: { '/dashboard/pos': 'Thermal', '/dashboard/barcode/printer': 'Labels', '/dashboard/receipts': '' } };
        assert.equal(pageKey('http://localhost/dashboard/pos/?_workspace=frame'), '/dashboard/pos');
        assert.equal(printerForPage(preferences, 'http://localhost/dashboard/pos'), 'Thermal');
        assert.equal(printerForPage(preferences, 'http://localhost/dashboard/barcode/printer?_workspace=frame'), 'Labels');
        assert.equal(printerForPage(preferences, 'http://localhost/dashboard/analytics'), 'A4');
        assert.equal(printerForPage(preferences, 'http://localhost/dashboard/receipts'), '');
        assert.equal(printerForPage({ printer: 'Legacy' }, 'http://localhost/dashboard/pos'), 'Legacy');
        const printers = ['A4', 'Thermal', 'Labels'].map(name => ({ name }));
        let changed;
        const menu = printerMenu(printers, preferences, (key, printer) => { changed = [key, printer]; });
        const pos = menu.find(item => item.label?.includes('نقطة البيع')).submenu;
        pos.find(item => item.label === 'Labels').click();
        assert.deepEqual(changed, ['/dashboard/pos', 'Labels']);
        pos[0].click();
        assert.deepEqual(changed, ['/dashboard/pos', undefined]);
        assert.equal(preferences.pagePrinters['/dashboard/barcode/printer'], 'Labels');
        let created = 0, destroyed = 0, printed;
        class Job {
            constructor() { created++; this.webContents = { setWindowOpenHandler() {}, executeJavaScript: async () => {},
                print: (options, done) => { printed = options; done(true); } }; }
            async loadURL(url) { assert.equal(url, 'http://localhost/up'); }
            destroy() { destroyed++; }
        }
        const owner = { webContents: { session: {}, getPrintersAsync: async () => printers } };
        const options = { BrowserWindow: Job, owner, origin: 'http://localhost', preferences,
            request: { pageUrl: 'http://localhost/dashboard/barcode/printer', html: '<html><head></head><body>Barcode</body></html>' } };
        assert.equal(await printSnapshot(options), true);
        assert.equal(printed.deviceName, 'Labels');
        assert.equal(printed.silent, true);
        assert.equal(printed.usePrinterDefaultPageSize, true);
        assert.equal(destroyed, 1);
        await assert.rejects(() => printSnapshot({ ...options, preferences: { printer: 'Missing' } }), /غير متاحة/);
        assert.equal(created, 1);
        await assert.rejects(() => printSnapshot({ ...options, request: { ...options.request, pageUrl: 'https://foreign.test' } }), /غير صالح/);
        console.log('DESKTOP_PRINTING_OK');
    })().catch(error => { console.error(error); process.exitCode = 1; });
    JAVASCRIPT;
    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/desktop/printing.cjs']);
    $process->mustRun();
    expect($process->getOutput())->toContain('DESKTOP_PRINTING_OK');
});

test('desktop routes iframe receipts and barcode popups to their own silent printer', function (): void {
    $project = dirname(__DIR__, 2);
    $script = <<<'JAVASCRIPT'
    const { app, BrowserWindow } = require('electron');
    const assert = require('node:assert/strict');
    const fs = require('node:fs');
    const http = require('node:http');
    const { printSnapshot } = require(process.argv[2] + '/resources/desktop/printing.cjs');
    app.disableHardwareAcceleration();
    app.setPath('userData', process.argv[3]);
    app.whenReady().then(async () => {
        const server = http.createServer((req, res) => { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end('<!doctype html><html><head></head><body>Test</body></html>'); });
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = 'http://127.0.0.1:' + server.address().port;
        const owner = new BrowserWindow({ show: false, webPreferences: { nodeIntegration: false, contextIsolation: true, sandbox: true } });
        owner.webContents.setWindowOpenHandler(() => ({ action: 'allow', overrideBrowserWindowOptions: { show: false } }));
        await owner.loadURL(origin + '/dashboard/pos');
        await owner.webContents.executeJavaScript(`window.requests = []; window.waslaDesktop = { print: async request => { requests.push(request); return true; } }; window.alert = message => { throw new Error(message); }; void 0;`);
        await owner.webContents.executeJavaScript(fs.readFileSync(process.argv[2] + '/resources/desktop/print-bridge.js', 'utf8'));
        await owner.webContents.executeJavaScript(`new Promise(resolve => {
            const frame = document.createElement('iframe');
            frame.src = '/dashboard/barcode/printer?_workspace=frame';
            frame.onload = () => { window.barcode = frame; resolve(); };
            document.body.appendChild(frame);
        })`);
        await owner.webContents.executeJavaScript(`(async () => {
            const popup = barcode.contentWindow.open('', '_blank', 'width=400,height=400');
            if (!popup) throw new Error('Popup blocked');
            popup.document.write('<html><head></head><body><svg id="barcode-svg"></svg><script>window.unwantedScript = true;<\/script></body></html>');
            popup.document.close();
            await popup.print();
            popup.close();
            let after = false;
            window.addEventListener('afterprint', () => { after = true; }, { once: true });
            await window.print();
            if (!after) throw new Error('afterprint was not dispatched');
        })()`);
        console.log('BRIDGE_READY');
        const requests = await owner.webContents.executeJavaScript('requests');
        assert.equal(requests.length, 2);
        assert.equal(new URL(requests[0].pageUrl).pathname, '/dashboard/barcode/printer');
        assert.ok(requests[0].html.includes('barcode-svg'));
        assert.ok(!requests[0].html.includes('<script>'));
        assert.equal(new URL(requests[1].pageUrl).pathname, '/dashboard/pos');
        const jobs = [];
        class PrintJob extends BrowserWindow {
            constructor(options) {
                super(options);
                this.webContents.print = (options, callback) => {
                    this.webContents.executeJavaScript('document.body.innerHTML').then(html => { jobs.push({ options, html }); callback(true); }).catch(error => callback(false, error.message));
                };
            }
        }
        owner.webContents.getPrintersAsync = async () => [{ name: 'Thermal' }, { name: 'Labels' }];
        const preferences = { pagePrinters: { '/dashboard/pos': 'Thermal', '/dashboard/barcode/printer': 'Labels' } };
        console.log('PRINT_JOBS_START');
        for (const request of requests) await printSnapshot({ BrowserWindow: PrintJob, owner, origin, preferences, request });
        assert.equal(jobs[0].options.deviceName, 'Labels');
        assert.ok(jobs[0].html.includes('barcode-svg'));
        assert.equal(jobs[1].options.deviceName, 'Thermal');
        owner.destroy();
        server.close();
        console.log('DESKTOP_PRINT_INTEGRATION_OK');
        app.exit(0);
    }).catch(error => { console.error(error); app.exit(1); });
    JAVASCRIPT;
    $temporary = sys_get_temp_dir().'/wasla-print-test-'.bin2hex(random_bytes(8));
    mkdir($temporary);
    $file = $temporary.'/test.cjs';
    file_put_contents($file, $script);
    $process = new Process([$project.'/node_modules/electron/dist/electron.exe', $file, $project, $temporary.'/profile'], $project, ['ELECTRON_RUN_AS_NODE' => false]);
    $process->setTimeout(45);
    try {
        try {
            $process->run();
        } catch (ProcessTimedOutException $error) {
            throw new RuntimeException($process->getOutput().$process->getErrorOutput(), previous: $error);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput().$process->getOutput());
        }
        expect($process->getOutput())->toContain('DESKTOP_PRINT_INTEGRATION_OK');
    } finally {
        unlink($file);
    }
})->skip(PHP_OS_FAMILY !== 'Windows' || ! is_file(dirname(__DIR__, 2).'/node_modules/electron/dist/electron.exe'), 'Requires Electron on Windows.');
