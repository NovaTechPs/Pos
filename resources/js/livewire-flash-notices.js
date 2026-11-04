const shown = new Set();

function showNotice(text, variant = 'error') {
    const message = String(text || '').trim();
    if (!message) return;

    const key = `${variant}:${message}`;
    if (shown.has(key)) return;
    shown.add(key);
    window.setTimeout(() => shown.delete(key), 10000);

    const toast = document.createElement('div');
    toast.dataset.livewireFlashToast = 'true';
    toast.setAttribute('role', variant === 'success' ? 'status' : 'alert');
    toast.dir = 'rtl';
    toast.className = `fixed left-4 top-4 z-[99999] max-w-md rounded-xl border px-4 py-3 text-sm font-bold text-white shadow-xl transition ${variant === 'success' ? 'border-emerald-200 bg-emerald-600' : 'border-rose-200 bg-rose-600'}`;
    toast.textContent = message;
    document.body.appendChild(toast);

    window.setTimeout(() => {
        toast.classList.add('opacity-0');
        window.setTimeout(() => toast.remove(), 250);
    }, 10000);
}

function scanVisibleNotices() {
    document.querySelectorAll('[data-flash-notice], [data-pos-notice], [role="alert"], div[class*="text-red-"][class*="bg-red-"], div[class*="text-rose-"][class*="bg-rose-"]').forEach((notice) => {
        if (notice.hidden || notice.dataset.livewireFlashToast === 'true') return;

        const variant = notice.dataset.flashVariant || (notice.classList.contains('text-emerald-800') ? 'success' : 'error');
        const message = notice.querySelector('p')?.textContent || notice.querySelector('.text-sm')?.textContent || notice.textContent;
        showNotice(message, variant);

        notice.hidden = true;
    });
}

document.addEventListener('livewire:init', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(({ snapshot }) => {
            try {
                const errors = JSON.parse(snapshot)?.memo?.errors || {};
                const message = Object.values(errors).flat()[0];
                if (message) showNotice(message, 'error');
            } catch (_) {
                // Notifications never interfere with a Livewire response.
            }
            window.setTimeout(scanVisibleNotices, 0);
        });
    });

    window.setTimeout(scanVisibleNotices, 0);
});

const observer = new MutationObserver(() => {
    window.setTimeout(scanVisibleNotices, 0);
});

observer.observe(document.body, { childList: true, subtree: true });

window.addEventListener('app-flash-notice', (event) => {
    showNotice(event.detail?.message, event.detail?.variant || 'error');
});

window.addEventListener('toast', (event) => {
    const variant = event.detail?.variant || (event.detail?.type === 'success' ? 'success' : 'error');
    showNotice(event.detail?.message, variant);
});
