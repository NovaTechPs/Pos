export function bindLoginProgress(form, view = window) {
    if (form.dataset.loginBound) return;
    form.dataset.loginBound = 'true';
    const button = form.querySelector('[type="submit"]');
    const label = form.querySelector('[data-login-label]');
    const pending = form.querySelector('[data-login-pending]');
    const status = form.querySelector('[data-login-status]');
    let submitting = false;
    let timer;
    const reset = () => {
        submitting = false;
        view.clearTimeout(timer);
        button.disabled = false;
        form.removeAttribute('aria-busy');
        label.hidden = false;
        pending.hidden = true;
        pending.textContent = 'جارٍ إرسال طلب الدخول…';
        status.hidden = true;
    };
    form.addEventListener('submit', event => {
        if (submitting) { event.preventDefault(); return; }
        submitting = true;
        button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        label.hidden = true;
        pending.hidden = false;
        status.hidden = false;
        status.textContent = 'جارٍ إرسال بيانات الدخول والتحقق من الحساب، يرجى الانتظار.';
        timer = view.setTimeout(() => {
            pending.textContent = 'جارٍ انتظار استجابة الخادم…';
            status.textContent = 'استغرق الاتصال وقتًا أطول من المعتاد. طلب الدخول قيد الانتظار؛ لا حاجة للضغط مرة أخرى.';
        }, 6000);
    });
    view.addEventListener('pageshow', reset);
}

if (typeof document !== 'undefined') {
    const initialize = () => document.querySelectorAll('[data-login-progress]').forEach(form => bindLoginProgress(form));
    document.addEventListener('DOMContentLoaded', initialize);
    document.addEventListener('livewire:navigated', initialize);
    initialize();
}
