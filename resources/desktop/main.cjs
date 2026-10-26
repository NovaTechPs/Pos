const { app, BrowserWindow, Menu, dialog, ipcMain, shell } = require('electron');
const fs = require('node:fs/promises');
const { createWriteStream } = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { initializeData, buildEnvironment, reservePort, runPhp, isLocalNavigation } = require('./runtime.cjs');

const smokeTest = process.argv.includes('--smoke-test');
if (smokeTest && process.env.WASLA_DESKTOP_TEST_DATA) app.setPath('userData', process.env.WASLA_DESKTOP_TEST_DATA);
app.setAppUserModelId('app.waslapos.desktop');
const singleInstance = app.requestSingleInstanceLock();
let window;
let server;
let synchronizing = false;
let syncTimer;
let closing = false;
let origin;
let preferences = {};
let synchronizeNow = async () => {};
const children = new Set();
const dataRoot = app.getPath('userData');

function stopChildren() {
    clearInterval(syncTimer);
    for (const child of children) {
        if (!child.killed) child.kill();
    }
}

async function showMenu() {
    const printers = await window.webContents.getPrintersAsync();
    Menu.setApplicationMenu(Menu.buildFromTemplate([
        { label: 'WaslaPOS', submenu: [
            { label: 'مزامنة الآن', click: async () => { await synchronizeNow(); if (!closing) window.webContents.reload(); } },
            { label: 'مجلد البيانات والنسخ الاحتياطية', click: () => shell.openPath(dataRoot) },
            { type: 'separator' }, { role: 'quit', label: 'خروج' },
        ] },
        { label: 'الطابعة', submenu: [
            { label: 'طابعة Windows الافتراضية', type: 'radio', checked: !preferences.printer,
                click: async () => { preferences.printer = ''; await savePreferences(); } },
            ...printers.map(printer => ({ label: printer.displayName || printer.name, type: 'radio',
                checked: preferences.printer === printer.name,
                click: async () => { preferences.printer = printer.name; await savePreferences(); } })),
            { type: 'separator' }, { label: 'تحديث قائمة الطابعات', click: showMenu },
        ] },
        { label: 'عرض', submenu: [{ role: 'reload', label: 'إعادة تحميل' },
            { role: 'togglefullscreen', label: 'ملء الشاشة' }, { role: 'resetzoom' }, { role: 'zoomin' }, { role: 'zoomout' }] },
        { role: 'editMenu', label: 'تحرير' },
    ]));
}

async function savePreferences() {
    await fs.writeFile(path.join(dataRoot, 'preferences.json'), JSON.stringify(preferences), { mode: 0o600 });
}

async function start() {
    const bundleRoot = app.isPackaged ? process.resourcesPath : path.join(__dirname, '../../storage/app/desktop-build/stage');
    const backend = path.join(bundleRoot, 'backend');
    const php = path.join(bundleRoot, 'php/php.exe');
    const ini = path.join(bundleRoot, 'php/php.ini');
    const state = await initializeData(dataRoot);
    const port = await reservePort();
    origin = `http://127.0.0.1:${port}`;
    const environment = buildEnvironment(process.env, dataRoot, backend, port, state.key);
    const phpOptions = { cwd: backend, env: environment, onSpawn: child => {
        children.add(child);
        child.on('exit', () => children.delete(child));
    } };
    const phpLog = createWriteStream(path.join(dataRoot, 'logs/backend.log'), { flags: 'a' });
    const startChild = args => {
        const child = spawn(php, ['-c', ini, ...args], { ...phpOptions, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
        children.add(child);
        child.stdout.pipe(phpLog, { end: false });
        child.stderr.pipe(phpLog, { end: false });
        child.on('exit', () => children.delete(child));
        return child;
    };

    const backupPath = path.join(dataRoot, 'backups', `before-${app.getVersion()}.sqlite`);
    if ((await fs.stat(state.database)).size > 0 && !(await fs.stat(backupPath).catch(() => null))) {
        await runPhp(php, ini, [path.join(backend, 'resources/desktop/backup.php'), state.database, backupPath], phpOptions);
    }
    await runPhp(php, ini, ['artisan', 'migrate', '--force', '--no-interaction'], phpOptions);
    server = startChild(['-S', `127.0.0.1:${port}`, '-t', path.join(backend, 'public'), path.join(backend, 'resources/desktop/router.php')]);
    server.on('error', error => { if (!closing) dialog.showErrorBox('WaslaPOS', error.message); });
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
        if (server.exitCode !== null) throw new Error('The local server stopped. Check logs/backend.log.');
        try {
            const response = await fetch(origin + '/up', { signal: AbortSignal.timeout(1000) });
            if (response.ok) { ready = true; break; }
        } catch { /* The local server is still starting. */ }
        await new Promise(resolve => setTimeout(resolve, 200));
    }
    if (!ready) throw new Error('The local server did not start. Check logs/backend.log.');

    try { preferences = JSON.parse(await fs.readFile(path.join(dataRoot, 'preferences.json'), 'utf8')); } catch { preferences = {}; }
    window = new BrowserWindow({ width: 1440, height: 960, minWidth: 1024, minHeight: 700, show: false,
        title: 'WaslaPOS', icon: path.join(backend, 'public/app-icon-512.png'),
        webPreferences: { preload: path.join(__dirname, 'preload.cjs'), nodeIntegration: false, contextIsolation: true, sandbox: true } });
    const consoleErrors = [];
    if (smokeTest) window.webContents.on('console-message', details => { if (details.level === 'error') consoleErrors.push(details.message); });
    window.webContents.on('will-navigate', (event, target) => {
        if (!isLocalNavigation(target, origin)) event.preventDefault();
    });
    window.webContents.setWindowOpenHandler(({ url }) => {
        if (url.startsWith('https://')) shell.openExternal(url);
        return { action: 'deny' };
    });
    window.webContents.on('dom-ready', () => {
        window.webContents.executeJavaScript(`window.print = async () => {
            try { await window.waslaDesktop.print(); }
            finally { window.dispatchEvent(new Event('afterprint')); }
        };`).catch(() => {});
    });
    ipcMain.handle('wasla:print', async event => {
        if (event.sender !== window.webContents || !isLocalNavigation(event.senderFrame.url, origin)) throw new Error('Printing is unavailable.');
        return new Promise((resolve, reject) => {
            window.webContents.print({ silent: true, printBackground: true, usePrinterDefaultPageSize: true,
                deviceName: preferences.printer || '', margins: { marginType: 'none' } }, (success, reason) => {
                if (success) resolve(true);
                else { dialog.showErrorBox('تعذر الطباعة', reason); reject(new Error(reason)); }
            });
        });
    });
    const synchronize = async () => {
        if (synchronizing || closing) return;
        synchronizing = true;
        try {
            await runPhp(php, ini, ['artisan', 'branch-sync:push', '--no-interaction'], phpOptions);
        } catch (error) { phpLog.write(error.message + '\n'); }
        finally { synchronizing = false; }
    };
    synchronizeNow = async () => {
        await synchronize();
        if (!closing) {
            try { await runPhp(php, ini, ['artisan', 'branch-sync:pull', '--no-interaction'], phpOptions); }
            catch (error) { phpLog.write(error.message + '\n'); }
        }
    };
    let ticks = 0;
    syncTimer = setInterval(async () => {
        await synchronize();
        if (++ticks % 30 === 0 && !closing) {
            const pull = startChild(['artisan', 'branch-sync:pull', '--no-interaction']);
            pull.on('error', error => phpLog.write(error.message + '\n'));
        }
    }, 10000);
    await window.loadURL(origin + '/login');
    await showMenu();
    if (smokeTest) {
        const details = await window.webContents.executeJavaScript(`({
            title: document.title, loginForm: !!document.querySelector('input[name="email"]'),
            action: document.querySelector('form')?.action,
            styles: [...document.querySelectorAll('link[rel="stylesheet"]')].map(link => link.href),
            text: document.body.innerText.slice(0, 500)
        })`);
        if (!details.loginForm || !details.action?.startsWith(origin)) throw new Error('The packaged login form did not load correctly.');
        await fs.writeFile(path.join(dataRoot, 'smoke-result.json'), JSON.stringify({ ...details, consoleErrors, database: state.database }, null, 2));
        await fs.writeFile(path.join(dataRoot, 'smoke-login.png'), (await window.webContents.capturePage()).toPNG());
        app.quit();
    } else {
        window.maximize();
        window.show();
    }
}

if (!singleInstance) app.quit();
else {
    app.on('second-instance', () => { if (window) { if (window.isMinimized()) window.restore(); window.focus(); } });
    app.whenReady().then(start).catch(async error => {
        await fs.mkdir(path.join(dataRoot, 'logs'), { recursive: true });
        await fs.writeFile(path.join(dataRoot, 'logs/startup-error.log'), error.stack || error.message);
        if (!smokeTest) dialog.showErrorBox('تعذر تشغيل WaslaPOS', error.message + '\n' + dataRoot);
        stopChildren();
        app.exit(1);
    });
    app.on('before-quit', () => { closing = true; stopChildren(); });
    app.on('window-all-closed', () => app.quit());
}
