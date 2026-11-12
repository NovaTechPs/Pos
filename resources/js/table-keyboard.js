/**
 * Arrow-key navigation for report tables.
 *
 * Any container marked with [data-keyboard-tables] gets keyboard-friendly tables:
 * - every body row becomes focusable (Tab reaches the first row of each table),
 * - ArrowDown / ArrowUp move row by row across all tables of the page in order,
 * - Home / End jump to the first / last row,
 * - Enter opens the row (its own click action, or its first link or button).
 * Handlers that already prevented the key (for example the statement page) are left alone.
 */
const CONTAINER = '[data-keyboard-tables]';
const ROW = 'tbody > tr';

function isNavigableRow(row) {
    return row.closest(CONTAINER) && !row.hasAttribute('data-statement-row') && row.offsetParent !== null && row.cells.length > 0;
}

function rowsIn(container) {
    return [...container.querySelectorAll(ROW)].filter(isNavigableRow);
}

function prepareRows() {
    document.querySelectorAll(CONTAINER).forEach((container) => {
        container.querySelectorAll('table').forEach((table) => {
            const rows = [...table.querySelectorAll(ROW)].filter(isNavigableRow);
            rows.forEach((row, index) => {
                if (!row.hasAttribute('tabindex')) row.setAttribute('tabindex', index === 0 ? '0' : '-1');
                row.setAttribute('data-keyboard-row', '');
            });
        });
    });
}

function focusRow(row) {
    if (!row) return;
    row.focus({ preventScroll: true });
    row.scrollIntoView({ block: 'nearest' });
}

function openRow(row) {
    if (row.hasAttribute('wire:click') || row.onclick) {
        row.click();
        return;
    }
    row.querySelector('a[href], button:not([disabled])')?.click();
}

document.addEventListener('keydown', (event) => {
    const row = event.target instanceof Element ? event.target.closest('tr[data-keyboard-row]') : null;
    if (!row || event.target !== row || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return;

    const rows = rowsIn(row.closest(CONTAINER));
    const index = rows.indexOf(row);
    const targets = {
        ArrowDown: rows[Math.min(rows.length - 1, index + 1)],
        ArrowUp: rows[Math.max(0, index - 1)],
        Home: rows[0],
        End: rows[rows.length - 1],
    };

    if (event.key in targets) {
        event.preventDefault();
        focusRow(targets[event.key]);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        openRow(row);
    }
});

let scheduled = false;
function schedulePrepare() {
    if (scheduled) return;
    scheduled = true;
    window.setTimeout(() => {
        scheduled = false;
        prepareRows();
    }, 0);
}

document.addEventListener('DOMContentLoaded', schedulePrepare);
document.addEventListener('livewire:navigated', schedulePrepare);
new MutationObserver(schedulePrepare).observe(document.documentElement, { childList: true, subtree: true });
