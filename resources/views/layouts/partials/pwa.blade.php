<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#2563eb">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="WaslaPOS">

<script>
    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register(@js(asset('sw.js')), { scope: @js(url('/').'/') }).catch((error) => {
            console.warn('WaslaPOS service worker registration failed:', error);
        });
    }
</script>
