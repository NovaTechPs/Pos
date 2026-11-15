/**
 * A stable id for the current browser tab or workspace tab.
 *
 * The POS keeps one unfinished invoice per tab: the id lives in window.name, which survives
 * refreshing the tab but is not shared with other tabs. Workspace tabs give their frames a
 * saved name ("ws-…"), so each workspace tab keeps its own invoice across reloads too.
 */
const TAB_ID = /^(ws|pos)-[A-Za-z0-9-]{6,40}$/;

function randomPart() {
    const value = typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : Math.random().toString(36).slice(2) + Date.now().toString(36);

    return value.replaceAll('-', '').slice(0, 20);
}

export function posDraftTabId() {
    if (!TAB_ID.test(window.name || '')) window.name = 'pos-' + randomPart();

    return window.name;
}

window.posDraftTabId = posDraftTabId;
