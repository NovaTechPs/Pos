<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WaslaPOS — النظام المركزي</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f3f6fc; color: #142443; font-family: 'Segoe UI', Tahoma, sans-serif; }
        main { width: min(520px, calc(100vw - 32px)); padding: 32px 24px; text-align: center; background: #fff; border: 1px solid #dbe4f3; border-radius: 20px; box-shadow: 0 12px 35px #2563eb14; }
        h1 { margin: 0 0 12px; font-size: 22px; }
        p { line-height: 1.9; margin: 8px 0; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin-top: 18px; }
        .button { border: 1px solid #c7d4ea; border-radius: 12px; padding: 10px 16px; font: inherit; font-weight: 700; background: #f8fafc; color: #142443; cursor: pointer; text-decoration: none; }
        .primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        [hidden] { display: none !important; }
        .offline { color: #92400e; }
    </style>
</head>
<body>
    <main data-desktop-central-page data-central-path="{{ $centralPath }}">
        <h1>تعمل هذه الصفحة من النظام المركزي</h1>
        <p data-central-status role="status" aria-live="polite">جارٍ فتحها من السيرفر لعرض البيانات المباشرة…</p>
        <p data-central-offline class="offline" hidden>لا يوجد اتصال بالسيرفر الآن. نقطة البيع تعمل دون اتصال، وتُزامَن الفواتير تلقائيًا عند عودة الإنترنت.</p>
        <div class="actions">
            <button type="button" class="button primary" data-central-open>فتح من النظام المركزي</button>
            @if ($localFallbackUrl)
                <a class="button" href="{{ $localFallbackUrl }}" data-central-local>{{ $localFallbackLabel }} (محلي)</a>
            @endif
            <a class="button" href="{{ route('tenant.pos') }}" target="_top">نقطة البيع</a>
        </div>
    </main>
    <script>
        (() => {
            const page = document.querySelector('[data-desktop-central-page]');
            const status = page.querySelector('[data-central-status]');
            const offline = page.querySelector('[data-central-offline]');
            const bridge = (() => { try { return window.top.waslaDesktop; } catch (error) { return undefined; } })();
            const open = async () => {
                if (!bridge?.openCentral) {
                    status.textContent = 'افتح هذه الصفحة من موقع النظام: {{ rtrim((string) config('branch-sync.server_url'), '/') }}' + page.dataset.centralPath;
                    return;
                }
                status.textContent = 'جارٍ فتحها من السيرفر لعرض البيانات المباشرة…';
                offline.hidden = true;
                try {
                    await bridge.openCentral(page.dataset.centralPath);
                    status.textContent = 'فُتحت الصفحة في نافذة النظام المركزي.';
                } catch (error) {
                    status.textContent = 'تعذر فتح الصفحة من السيرفر.';
                    offline.hidden = false;
                }
            };
            page.querySelector('[data-central-open]').addEventListener('click', open);
            open();
        })();
    </script>
</body>
</html>
