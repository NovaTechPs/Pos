const { isLocalNavigation } = require('./runtime.cjs');

const printPages = [
    ['/dashboard/pos', 'نقطة البيع — الفواتير الحرارية'],
    ['/dashboard/barcode/printer', 'ملصقات الباركود'],
    ['/dashboard/sale-invoices', 'فواتير المبيعات'],
    ['/dashboard/purchase-invoices', 'فواتير المشتريات'],
    ['/dashboard/wholesale-sales', 'مبيعات الجملة'],
    ['/dashboard/wholesale-invoices', 'فواتير الجملة'],
    ['/dashboard/customer', 'كشوف حساب العملاء والموردين'],
    ['/dashboard/analytics', 'تقارير المبيعات'],
    ['/dashboard/daily-settlement', 'التسوية اليومية'],
    ['/dashboard/payment', 'سندات الصرف'],
    ['/dashboard/receipts', 'سندات القبض'],
    ['/dashboard/payroll-manager', 'الرواتب'],
];

function pageKey(url) {
    try { return new URL(url).pathname.replace(/\/+$/, '') || '/'; }
    catch { return '/'; }
}

function printerForPage(preferences, url) {
    const key = pageKey(url);
    return Object.hasOwn(preferences.pagePrinters || {}, key)
        ? preferences.pagePrinters[key] : preferences.printer || '';
}

function printerMenu(printers, preferences, change, pages = printPages) {
    const choices = (key) => {
        const selected = key === null ? preferences.printer || '' : preferences.pagePrinters?.[key];
        const result = [];
        if (key !== null) result.push({ label: 'استخدام الطابعة الافتراضية للتطبيق', type: 'radio', checked: selected === undefined,
            click: () => change(key, undefined) });
        result.push({ label: 'طابعة Windows الافتراضية', type: 'radio', checked: selected === '', click: () => change(key, '') });
        if (selected && !printers.some(printer => printer.name === selected)) {
            result.push({ label: `${selected} — غير متصلة`, type: 'radio', checked: true, enabled: false });
        }
        result.push(...printers.map(printer => ({ label: printer.displayName || printer.name, type: 'radio',
            checked: selected === printer.name, click: () => change(key, printer.name) })));
        return result;
    };
    return [{ label: 'الطابعة الافتراضية للتطبيق', submenu: choices(null) }, { type: 'separator' },
        ...pages.map(([key, label]) => ({ label, submenu: choices(key) }))];
}

async function printSnapshot({ BrowserWindow, owner, origin, preferences, request }) {
    if (!request || typeof request.html !== 'string' || request.html.length > 20_000_000
        || !isLocalNavigation(request.pageUrl, origin)) throw new Error('طلب الطباعة غير صالح.');
    const printer = printerForPage(preferences, request.pageUrl);
    const printers = await owner.webContents.getPrintersAsync();
    if (printer && !printers.some(item => item.name === printer)) {
        throw new Error(`الطابعة المحددة لهذه الصفحة غير متاحة: ${printer}. اختر طابعة أخرى من قائمة الطابعة.`);
    }
    const job = new BrowserWindow({ show: false, width: 1100, height: 900,
        webPreferences: { nodeIntegration: false, contextIsolation: true, sandbox: true,
            session: owner.webContents.session } });
    try {
        job.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
        const base = new URL(request.pageUrl).href.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
        const html = request.html.replace(/<head([^>]*)>/i, `<head$1><base href="${base}"><meta http-equiv="Content-Security-Policy" content="script-src 'none'; object-src 'none'; frame-src 'none'">`);
        await job.loadURL(`${origin}/up`);
        await job.webContents.executeJavaScript(`new Promise(resolve => {
            document.open();
            window.addEventListener('load', resolve, { once: true });
            document.write(${JSON.stringify(html)});
            document.close();
        })`);
        await job.webContents.executeJavaScript(`Promise.all([document.fonts.ready, ...Array.from(document.images, image => image.complete ? Promise.resolve() : new Promise(resolve => { image.onload = image.onerror = resolve; }))])`);
        return await new Promise((resolve, reject) => {
            job.webContents.print({ silent: true, printBackground: true, usePrinterDefaultPageSize: true,
                deviceName: printer, margins: { marginType: 'none' } }, (success, reason) => {
                if (success) resolve(true);
                else reject(new Error(reason || 'تعذرت طباعة المستند.'));
            });
        });
    } finally { job.destroy(); }
}

module.exports = { pageKey, printerForPage, printerMenu, printSnapshot };
