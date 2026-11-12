/**
 * Helpers for the window that shows store pages from the central server.
 * Only paths on the central origin are opened there; everything else stays outside the app.
 */
function centralUrl(serverUrl, target) {
    const server = new URL(serverUrl);
    if (server.protocol !== 'https:') throw new Error('عنوان السيرفر غير آمن.');
    if (typeof target !== 'string' || !target.startsWith('/') || target.startsWith('//') || target.includes('\\') || target.length > 2000) {
        throw new Error('الصفحة المطلوبة غير صالحة.');
    }
    const url = new URL(target, server);
    if (url.origin !== server.origin) throw new Error('الصفحة المطلوبة غير صالحة.');
    return url.href;
}

function isCentralNavigation(target, serverUrl) {
    try { return new URL(target).origin === new URL(serverUrl).origin; } catch { return false; }
}

/** Whether the central server answers quickly enough to open its pages. */
async function centralReachable(fetchFn, serverUrl, timeoutMs = 4000) {
    try {
        const response = await fetchFn(new URL('/up', serverUrl).href, { signal: AbortSignal.timeout(timeoutMs), cache: 'no-store' });
        return response.ok;
    } catch {
        return false;
    }
}

/** Network failures (not a cancelled navigation) mean the device lost the connection to the server. */
function isConnectionFailure(errorCode) {
    return errorCode <= -100 && errorCode > -200 || errorCode === -7 || errorCode === -21;
}

module.exports = { centralUrl, isCentralNavigation, centralReachable, isConnectionFailure };
