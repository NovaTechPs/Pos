document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || !(event.target instanceof HTMLElement)) return;
    if (event.isComposing || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    if (!event.target.matches('input, select, textarea')) return;
    if (event.target.closest('[contenteditable="true"], [data-enter-nav-ignore]')) return;
    if (event.target.matches('textarea') && event.target.closest('[data-arrow-nav]')) return;

    const form = event.target.closest('form');
    if (!form) return;

    const submitAction = form.getAttribute('wire:submit') || form.getAttribute('wire:submit.prevent') || '';
    if (/^(search|applyFilters|filter|preview|calculate|lookup)/i.test(submitAction.trim())) return;

    event.preventDefault();
}, true);

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || event.isComposing || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    if (!(event.target instanceof HTMLElement) || !event.target.matches('input, select, textarea')) return;
    if (event.target.closest('[data-arrow-nav], [contenteditable="true"], [data-enter-nav-ignore]')) return;

    const form = event.target.closest('form');
    if (!form || !document.body.classList.contains('app-responsive')) return;
    const action = form.getAttribute('wire:submit') || form.getAttribute('wire:submit.prevent') || '';
    if (/^(search|applyFilters|filter|preview|calculate|lookup)/i.test(action.trim())) return;

    const fields = [...form.querySelectorAll('input, select, textarea')].filter((field) =>
        !field.matches(':disabled, [readonly], [type="hidden"], [type="submit"], [type="button"], [type="reset"]')
        && !field.closest('[inert], [data-enter-nav-ignore]') && field.getClientRects().length > 0,
    );
    const index = fields.indexOf(event.target);
    if (index === -1) return;
    event.preventDefault();
    const nextSelector = event.target.getAttribute('data-enter-next');
    const next = nextSelector ? form.querySelector(nextSelector) : fields[index + 1];
    if (next && !next.matches(':disabled, [readonly]') && next.getClientRects().length > 0) {
        next.focus();
    } else if (!next) {
        [...form.elements].find((field) => field.matches('button[type="submit"], input[type="submit"]')
            && !field.matches(':disabled') && field.getClientRects().length > 0)?.focus();
    }
});
