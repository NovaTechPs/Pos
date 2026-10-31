export function workspaceUrl(value, base) {
    const url = new URL(value, base);
    const origin = new URL(base).origin;
    if (url.origin !== origin || !['http:', 'https:'].includes(url.protocol)
        || !/^\/(dashboard|admin|settings)(\/|$)/.test(url.pathname)) return null;
    url.searchParams.delete('_workspace');
    url.hash = '';
    return url.href;
}

export function cleanWorkspaceAddress(view) {
    const url = new URL(view.location.href);
    if (!url.searchParams.has('_workspace')) return;
    url.searchParams.delete('_workspace');
    view.history.replaceState(view.history.state, '', url.href);
}

export function normalizeWorkspaceFrame(view) {
    if (view.self === view.top) return false;
    const page = view.document;
    page.documentElement.setAttribute('data-workspace-frame', 'true');
    if (!page.body) return true;
    page.body.classList.add('workspace-frame-body');
    // Some proxies and embedded browsers omit Sec-Fetch-Dest. Keep the same
    // Livewire content nodes, but remove the shell rendered around them.
    for (const shell of page.querySelectorAll('[data-workspace-tabs]')) {
        const initial = shell.querySelector('[data-workspace-initial]');
        if (initial) shell.replaceWith(...initial.childNodes);
    }
    return true;
}

export function interceptWorkspaceNavigation(event, base, openPage) {
    const link = event.target.closest?.('a[href]');
    const plainActivation = event.type === 'keydown' ? event.key === 'Enter' : event.button === 0;
    if (!link || event.defaultPrevented || !plainActivation || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
        || link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('wire:click')
        || !link.hasAttribute('wire:navigate')) return false;
    const raw = link.getAttribute('href');
    if (!raw || raw.startsWith('#')) return false;
    const url = workspaceUrl(raw, base);
    if (!url) return false;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (event.type === 'mousedown') return true;
    openPage(url, link.textContent.trim());
    link.closest('[data-flux-sidebar][data-flux-sidebar-on-mobile]')?.querySelector('[data-flux-sidebar-collapse] button')?.click();
    return true;
}

export function workspaceUsesFullWidth(url) {
    return Boolean(url && /\/dashboard\/pos\/?$/.test(new URL(url).pathname));
}

export function readWorkspaceSnapshot(raw, base) {
    try {
        const data = JSON.parse(raw);
        if (!Array.isArray(data?.tabs)) return null;
        const tabs = [];
        for (const item of data.tabs.slice(0, 50)) {
            if (typeof item?.url !== 'string' || typeof item.title !== 'string') continue;
            let url;
            try { url = workspaceUrl(item.url, base); } catch { continue; }
            if (url && !tabs.some(tab => tab.url === url)) tabs.push({url, title: item.title.slice(0, 160)});
        }
        let activeUrl = null;
        try { if (typeof data.activeUrl === 'string') activeUrl = workspaceUrl(data.activeUrl, base); } catch {}
        return tabs.length ? {tabs, activeUrl: tabs.some(tab => tab.url === activeUrl) ? activeUrl : tabs[0].url} : null;
    } catch { return null; }
}

export class WorkspaceTabState {
    constructor(url, title) {
        this.tabs = [{ id: 'initial', url, title }];
        this.active = 'initial';
        this.sequence = 0;
    }
    open(url, title) {
        let tab = this.tabs.find(tab => tab.url === url);
        if (!tab) {
            tab = { id: `page-${++this.sequence}`, url, title };
            this.tabs.push(tab);
        }
        this.active = tab.id;
        return tab;
    }
    select(id) {
        if (this.tabs.some(tab => tab.id === id)) this.active = id;
    }
    close(id) {
        const index = this.tabs.findIndex(tab => tab.id === id);
        if (index < 0) return;
        this.tabs.splice(index, 1);
        if (this.active === id) this.active = this.tabs[Math.max(0, index - 1)]?.id ?? null;
    }
}

export function cycleWorkspaceTab(event, state, onSelect) {
    const ctrlTab = event.key === 'Tab' && event.ctrlKey && !event.altKey && !event.metaKey;
    const ctrlSpace = (event.code === 'Space' || event.key === ' ' || event.key === 'Spacebar') && event.ctrlKey && !event.altKey && !event.metaKey;
    const altPage = ['PageDown', 'PageUp'].includes(event.key) && event.altKey && !event.ctrlKey && !event.shiftKey && !event.metaKey;
    if ((!ctrlTab && !ctrlSpace && !altPage) || event.isComposing || state.tabs.length < 2) return false;
    event.preventDefault();
    event.stopImmediatePropagation();
    const current = Math.max(0, state.tabs.findIndex(tab => tab.id === state.active));
    const backwards = altPage ? event.key === 'PageUp' : event.shiftKey;
    const next = (current + (backwards ? -1 : 1) + state.tabs.length) % state.tabs.length;
    state.select(state.tabs[next].id);
    onSelect(state.active);
    return true;
}

export function loadWorkspaceFrame(frame) {
    if (!frame?.dataset.src || frame.dataset.started) return false;
    const url = workspaceUrl(frame.dataset.src, frame.dataset.src);
    if (!url) return false;
    frame.dataset.started = 'true';
    frame.src = url;
    frame.setAttribute('aria-busy', 'true');
    return true;
}

export function syncWorkspacePanels(panels, active) {
    let visible = false;
    for (const view of panels.children) {
        if (view.hasAttribute?.('data-workspace-loading')) continue;
        const selected = !visible && view.id === `workspace-panel-${active}`;
        visible ||= selected;
        view.hidden = !selected;
        view.inert = !selected;
        view.setAttribute('aria-hidden', String(!selected));
        if (selected) view.style.removeProperty('display');
        else view.style.setProperty('display', 'none', 'important');
    }
}

export function workspaceLifecycle(findRoot, mount) {
    let root;
    let cleanup;
    return {
        init() {
            const next = findRoot();
            if (next === root || next?.__workspaceMounted) return;
            this.dispose();
            if (!next) return;
            root = next;
            root.__workspaceMounted = true;
            cleanup = mount(root);
        },
        dispose() {
            cleanup?.();
            if (root) delete root.__workspaceMounted;
            root = undefined;
            cleanup = undefined;
        },
    };
}

function mountWorkspace(root) {
    const initialUrl = workspaceUrl(window.location.href, window.location.href);
    if (!initialUrl) return;
    const state = new WorkspaceTabState(initialUrl, document.title.replace(/ - [^-]+$/, ''));
    const list = root.querySelector('[data-workspace-tab-list]');
    const panels = root.querySelector('[data-workspace-panels]');
    const initial = root.querySelector('[data-workspace-initial]');
    const views = new Map([['initial', initial]]);
    const bindings = new Map();
    const storageKey = root.dataset.persistenceKey;
    let restoring = true;
    let saved;
    try { saved = storageKey ? readWorkspaceSnapshot(sessionStorage.getItem(storageKey), initialUrl) : null; } catch { saved = null; }
    function persist() {
        if (restoring || !storageKey) return;
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({tabs: state.tabs.map(({url, title}) => ({url, title})), activeUrl: state.tabs.find(tab => tab.id === state.active)?.url}));
        } catch { /* Storage can be unavailable in private or restricted browsing. */ }
    }
    initial.id = 'workspace-panel-initial';
    initial.setAttribute('aria-labelledby', 'workspace-tab-initial');

    function focusPage(id) {
        const view = views.get(id);
        if (!view) return;
        if (view.tagName === 'IFRAME') view.contentWindow?.focus();
        else view.querySelector('[data-pos-barcode-input]')?.focus({ preventScroll: true });
    }
    function draw() {
        const active = state.tabs.find(tab => tab.id === state.active);
        const activeView = views.get(state.active);
        if (!restoring && activeView?.tagName === 'IFRAME' && loadWorkspaceFrame(activeView)) active.loading = true;
        list.replaceChildren();
        for (const tab of state.tabs) {
            const group = document.createElement('div');
            group.className = 'workspace-tab';
            group.dataset.active = String(state.active === tab.id);
            const button = document.createElement('button');
            button.type = 'button';
            button.id = `workspace-tab-${tab.id}`;
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', String(state.active === tab.id));
            button.setAttribute('aria-controls', `workspace-panel-${tab.id}`);
            button.tabIndex = state.active === tab.id ? 0 : -1;
            button.textContent = tab.title;
            button.title = tab.title;
            button.setAttribute('aria-busy', String(Boolean(tab.loading)));
            if (tab.loading) {
                const spinner = document.createElement('span');
                spinner.className = 'workspace-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                button.prepend(spinner);
            }
            button.addEventListener('click', () => { state.select(tab.id); draw(); focusPage(tab.id); });
            button.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const index = state.tabs.findIndex(item => item.id === tab.id);
                const direction = root.dir === 'rtl' ? -1 : 1;
                let next = event.key === 'Home' ? 0 : event.key === 'End' ? state.tabs.length - 1
                    : (index + (event.key === 'ArrowRight' ? direction : -direction) + state.tabs.length) % state.tabs.length;
                state.select(state.tabs[next].id);
                draw();
                document.getElementById(`workspace-tab-${state.active}`)?.focus();
            });
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'workspace-tab-close';
            close.textContent = '×';
            close.setAttribute('aria-label', `إغلاق ${tab.title}`);
            close.addEventListener('click', () => closeTab(tab.id));
            group.append(button, close);
            list.append(group);
        }
        syncWorkspacePanels(panels, state.active);
        document.body.classList.toggle('workspace-pos-active', workspaceUsesFullWidth(state.tabs.find(tab => tab.id === state.active)?.url));
        const loading = root.querySelector('[data-workspace-loading]');
        if (loading) {
            loading.hidden = !active?.loading && !active?.loadError;
            loading.querySelector('[data-workspace-loading-text]').textContent = active?.loadError
                ? 'تعذر تحميل الصفحة. حاول مرة أخرى.' : 'جارٍ فتح ' + (active?.title || 'الصفحة') + '…';
            loading.querySelector('[data-workspace-retry]').hidden = !active?.loadError;
            loading.querySelector('.workspace-spinner').hidden = Boolean(active?.loadError);
        }
        persist();
        list.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
    function closeTab(id) {
        const view = views.get(id);
        let page = document;
        try { if (view?.tagName === 'IFRAME') page = view.contentDocument; } catch { page = null; }
        const scope = id === 'initial' ? initial : page;
        if (scope?.querySelector('[data-pos-cart-line]')
            && !window.confirm('هل تريد إغلاق تبويب الفاتورة؟ ستبقى مسودة السلة محفوظة لهذا الحساب.')) return;
        state.close(id);
        if (view?.tagName === 'IFRAME') {
            bindings.get(id)?.();
            bindings.delete(id);
        }
        view?.remove();
        views.delete(id);
        if (!state.tabs.length) openPage(root.dataset.homeUrl || '/dashboard', 'لوحة التحكم');
        else { draw(); focusPage(state.active); }
    }
    function openPage(raw, title) {
        const url = workspaceUrl(raw, window.location.href);
        if (!url) return false;
        const tab = state.open(url, title || 'صفحة');
        if (!views.has(tab.id)) {
            const frame = document.createElement('iframe');
            frame.className = 'workspace-frame';
            frame.id = `workspace-panel-${tab.id}`;
            frame.title = tab.title;
            frame.setAttribute('role', 'tabpanel');
            frame.setAttribute('aria-labelledby', `workspace-tab-${tab.id}`);
            frame.dataset.src = url;
            frame.addEventListener('load', () => {
                if (!frame.dataset.started) return;
                try { if (frame.contentWindow.location.href === 'about:blank') return; } catch {}
                tab.loading = false;
                tab.loadError = false;
                frame.setAttribute('aria-busy', 'false');
                draw();
                bindings.get(tab.id)?.();
                try {
                    const child = frame.contentDocument;
                    const childUrl = frame.contentWindow.location;
                    if (child.body?.dataset.workspaceContext && child.body.dataset.workspaceContext !== document.body.dataset.workspaceContext) {
                        window.location.reload();
                        return;
                    }
                    if (childUrl.origin !== window.location.origin) return;
                    normalizeWorkspaceFrame(frame.contentWindow);
                    if (/^\/(login|register)(\/|$)/.test(childUrl.pathname)) {
                        window.location.assign(childUrl.href);
                        return;
                    }
                    const click = event => intercept(event, childUrl.href);
                    const press = event => {
                        if (event.type === 'keydown' && cycleWorkspaceTab(event, state, id => { draw(); focusPage(id); })) return;
                        if (event.type !== 'keydown' || event.key === 'Enter') intercept(event, childUrl.href);
                    };
                    child.addEventListener('click', click, true);
                    child.addEventListener('mousedown', press, true);
                    child.addEventListener('keydown', press, true);
                    const contextChanged = () => window.location.reload();
                    frame.contentWindow.addEventListener('tenant-changed', contextChanged);
                    frame.contentWindow.addEventListener('branch-changed', contextChanged);
                    bindings.set(tab.id, () => {
                        child.removeEventListener('click', click, true);
                        child.removeEventListener('mousedown', press, true);
                        child.removeEventListener('keydown', press, true);
                        frame.contentWindow?.removeEventListener('tenant-changed', contextChanged);
                        frame.contentWindow?.removeEventListener('branch-changed', contextChanged);
                    });
                } catch { /* The frame may be navigating while its document changes. */ }
            });
            frame.addEventListener('error', () => {
                tab.loading = false;
                tab.loadError = true;
                frame.setAttribute('aria-busy', 'false');
                draw();
            });
            views.set(tab.id, frame);
            panels.append(frame);
        }
        draw();
        root.querySelector('[data-workspace-menu]')?.removeAttribute('open');
        focusPage(tab.id);
        return true;
    }
    function intercept(event, base = window.location.href) {
        return interceptWorkspaceNavigation(event, base, openPage);
    }
    const click = event => intercept(event);
    const press = event => {
        if (event.type !== 'keydown' || event.key === 'Enter') intercept(event);
    };
    const key = event => {
        if (cycleWorkspaceTab(event, state, id => { draw(); focusPage(id); })) return;
        if (state.active !== 'initial' && /^F\d+$/.test(event.key)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };
    const retry = () => {
        const active = state.tabs.find(tab => tab.id === state.active);
        const frame = views.get(state.active);
        if (!active || frame?.tagName !== 'IFRAME') return;
        delete frame.dataset.started;
        active.loadError = false;
        draw();
    };
    root.querySelector('[data-workspace-retry]')?.addEventListener('click', retry);
    const contextChanged = () => window.location.reload();
    document.addEventListener('click', click, true);
    document.addEventListener('mousedown', press, true);
    document.addEventListener('keydown', press, true);
    document.addEventListener('keydown', key, true);
    window.addEventListener('tenant-changed', contextChanged);
    window.addEventListener('branch-changed', contextChanged);
    if (saved) {
        if (!saved.tabs.some(tab => tab.url === initialUrl)) {
            state.close('initial');
            views.delete('initial');
            initial.remove();
        }
        for (const tab of saved.tabs) openPage(tab.url, tab.title);
        const active = state.tabs.find(tab => tab.url === saved.activeUrl);
        if (active) state.select(active.id);
    }
    restoring = false;
    draw();
    return () => {
        root.querySelector('[data-workspace-retry]')?.removeEventListener('click', retry);
        document.body.classList.remove('workspace-pos-active');
        document.removeEventListener('click', click, true);
        document.removeEventListener('mousedown', press, true);
        document.removeEventListener('keydown', press, true);
        document.removeEventListener('keydown', key, true);
        window.removeEventListener('tenant-changed', contextChanged);
        window.removeEventListener('branch-changed', contextChanged);
        bindings.forEach(cleanup => cleanup());
    };
}

if (typeof document !== 'undefined') {
    normalizeWorkspaceFrame(window);
    cleanWorkspaceAddress(window);
    const lifecycle = workspaceLifecycle(
        () => window.self === window.top ? document.querySelector('[data-workspace-tabs]') : null,
        mountWorkspace,
    );
    document.addEventListener('DOMContentLoaded', () => { normalizeWorkspaceFrame(window); lifecycle.init(); }, { once: true });
    document.addEventListener('livewire:navigated', () => { normalizeWorkspaceFrame(window); cleanWorkspaceAddress(window); lifecycle.init(); });
    document.addEventListener('livewire:navigating', () => lifecycle.dispose());
}
