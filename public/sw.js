self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate' || event.request.method !== 'GET') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => new Response(
        '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WaslaPOS</title><body><h1>لا يوجد اتصال بالإنترنت</h1><p>تحقق من اتصال الإنترنت ثم أعد تحميل الصفحة لمتابعة استخدام WaslaPOS.</p><button onclick="location.reload()">إعادة المحاولة</button></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } },
    )));
});
