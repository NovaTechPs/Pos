/**
 * Arrow-key navigation for compact data-entry forms.
 *
 * Inside any element marked with [data-arrow-nav]:
 *  - ArrowDown / Enter move to the next field, ArrowUp to the previous one.
 *  - Elements marked [data-arrow-nav-item] (e.g. search results) join the order,
 *    so ArrowDown from a search box steps into its results.
 *  - Enter on a button keeps its normal click behaviour.
 */
const FIELD_SELECTOR = [
    'input:not([type="hidden"]):not([disabled])',
    'select:not([disabled])',
    '[data-arrow-nav-item]:not([disabled])',
].join(', ');

const isVisible = (element) => element.getClientRects().length > 0;

function navigationFields(container) {
    return Array.from(container.querySelectorAll(FIELD_SELECTOR)).filter(isVisible);
}

function focusField(field) {
    field.focus({ preventScroll: false });

    if (field instanceof HTMLInputElement) {
        try {
            field.select();
        } catch {
            // Some input types (date, checkbox) cannot be selected.
        }
    }
}

document.addEventListener('keydown', (event) => {
    if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;
    if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || event.isComposing) return;
    if (!(event.target instanceof HTMLElement)) return;

    const container = event.target.closest('[data-arrow-nav]');
    if (!container || event.target.closest('textarea, [contenteditable="true"]')) return;

    const isButton = event.target.matches('button, [role="button"]');
    if (event.key === 'Enter' && isButton) return;

    const fields = navigationFields(container);
    const index = fields.indexOf(event.target);
    if (index === -1) return;

    const step = event.key === 'ArrowUp' ? -1 : 1;
    const next = fields[index + step];
    event.preventDefault();

    if (next) {
        focusField(next);
    }
});
