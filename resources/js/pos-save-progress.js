const saveActions = new Set(['checkout', 'checkoutAndPrint', 'saveOrEditInvoice', 'confirmCustomerPayment', 'confirmBelowCostCheckout', 'sendInvoiceWhatsApp', 'downloadInvoicePdf']);

export function createPosSaveInterceptor() {
    const active = new WeakSet();
    return ({ action, onFinish }) => {
        const root = action.component.el;
        if (!root?.matches('[data-pos-page]')) return;
        if (active.has(root)) {
            action.cancel();
            return;
        }
        if (!saveActions.has(action.name)) return;
        const overlay = root.querySelector('[data-pos-save-overlay]');
        if (!overlay) return;
        active.add(root);
        root.setAttribute('aria-busy', 'true');
        overlay.style.display = 'flex';
        onFinish(() => {
            active.delete(root);
            root.removeAttribute('aria-busy');
            overlay.style.display = 'none';
        });
    };
}

if (typeof document !== 'undefined') {
    let registered = false;
    const initialize = () => {
        if (registered || !window.Livewire) return;
        registered = true;
        window.Livewire.interceptAction(createPosSaveInterceptor());
    };
    document.addEventListener('livewire:init', initialize);
    initialize();
}
