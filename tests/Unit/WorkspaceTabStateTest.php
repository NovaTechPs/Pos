<?php

use Symfony\Component\Process\Process;

test('workspace deduplicates pages preserves tab identities and closes to adjacent tabs', function (): void {
    $script = <<<'JAVASCRIPT'
    import fs from 'node:fs';
    import assert from 'node:assert/strict';
    const source = fs.readFileSync('resources/js/workspace-tabs.js', 'utf8');
    const { WorkspaceTabState, workspaceUrl, syncWorkspacePanels, workspaceLifecycle, interceptWorkspaceNavigation, workspaceUsesFullWidth, readWorkspaceSnapshot, loadWorkspaceFrame } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
    const base = 'https://pos.example/dashboard/pos';
    assert.equal(workspaceUsesFullWidth(base), true);
    assert.equal(workspaceUsesFullWidth(base + '/?_workspace=frame'), true);
    assert.equal(workspaceUsesFullWidth('https://pos.example/dashboard/product'), false);
    assert.equal(workspaceUsesFullWidth(undefined), false);
    assert.equal(workspaceUrl('/dashboard/product?_workspace=frame&search=44', base), 'https://pos.example/dashboard/product?search=44');
    assert.equal(workspaceUrl('https://evil.example/dashboard', base), null);
    assert.equal(workspaceUrl('javascript:alert(1)', base), null);
    assert.equal(workspaceUrl('/logout', base), null);
    const tabs = new WorkspaceTabState(base, 'نقطة البيع');
    const products = tabs.open('https://pos.example/dashboard/product', 'الأصناف');
    const customer = tabs.open('https://pos.example/dashboard/customer', 'الزبائن');
    tabs.select('initial');
    assert.equal(tabs.tabs.length, 3);
    assert.equal(tabs.open(products.url, 'الأصناف'), products, 'Switching recreated the page');
    assert.equal(tabs.tabs.length, 3);
    tabs.close(customer.id);
    assert.equal(tabs.active, products.id, 'Closing an inactive tab changed selection');
    tabs.close(products.id);
    assert.equal(tabs.active, 'initial');
    tabs.select('missing');
    assert.equal(tabs.active, 'initial');
    tabs.close('initial');
    assert.equal(tabs.active, null);
    assert.equal(tabs.tabs.length, 0);
    assert.notEqual(tabs.open(base, 'نقطة البيع').id, 'initial');
    const makePanel = id => ({
        id: 'workspace-panel-' + id, hidden: false, inert: false,
        setAttribute(name, value) { this[name] = value; },
        style: {display: '', setProperty(name, value, priority) { this[name] = value; this.priority = priority; }, removeProperty(name) { delete this[name]; }},
    });
    const panels = {children: [makePanel('initial'), makePanel('page-1'), makePanel('page-2')]};
    for (const active of ['page-1', 'page-2', 'initial']) {
        syncWorkspacePanels(panels, active);
        assert.equal(panels.children.filter(view => !view.hidden).length, 1);
        for (const view of panels.children) {
            assert.equal(view.inert, view.id !== 'workspace-panel-' + active);
            if (view.hidden) assert.equal(view.style.display, 'none');
        }
    }
    let currentRoot = {};
    let mounts = 0, disposals = 0;
    const lifecycle = workspaceLifecycle(() => currentRoot, () => { mounts++; return () => disposals++; });
    lifecycle.init();
    lifecycle.init();
    assert.equal(mounts, 1, 'Repeated page-ready events recreated existing tabs');
    assert.equal(disposals, 0, 'Existing tabs were discarded');
    currentRoot = {};
    lifecycle.init();
    assert.equal(mounts, 2);
    assert.equal(disposals, 1);
    lifecycle.dispose();
    assert.equal(disposals, 2);
    const opened = [];
    let livewireNavigations = 0;
    const link = {
        target: '', textContent: 'الأصناف',
        hasAttribute(name) { return name === 'wire:navigate'; },
        getAttribute() { return '/dashboard/product'; },
        closest() { return null; },
    };
    function activate(type, extra = {}) {
        const event = {type, button: 0, target: {closest: () => link}, defaultPrevented: false, stopped: false,
            preventDefault() {this.defaultPrevented = true;}, stopImmediatePropagation() {this.stopped = true;}, ...extra};
        interceptWorkspaceNavigation(event, base, url => opened.push(url));
        if (type === 'mousedown' && !event.stopped) livewireNavigations++;
        return event;
    }
    activate('mousedown');
    assert.equal(livewireNavigations, 0, 'Livewire started navigation before the tab opened');
    assert.equal(opened.length, 0, 'Mouse press opened a tab before click');
    activate('click');
    assert.equal(opened.length, 1);
    activate('keydown', {key: 'Enter'});
    assert.equal(opened.length, 2);
    assert.equal(activate('mousedown', {ctrlKey: true}).stopped, false, 'Modified clicks should retain browser behavior');
    const saved = readWorkspaceSnapshot(JSON.stringify({tabs: [
        {url: base, title: 'نقطة البيع'}, {url: '/dashboard/product', title: 'الأصناف'},
        {url: 'https://evil.example/dashboard', title: 'External'}, {url: '/logout', title: 'Logout'},
        {url: '/dashboard/product', title: 'Duplicate'}], activeUrl: base}), base);
    assert.equal(saved.tabs.length, 2);
    assert.equal(saved.activeUrl, base);
    assert.equal(readWorkspaceSnapshot('corrupt', base), null);
    const restored = new WorkspaceTabState(base, 'نقطة البيع');
    saved.tabs.forEach(tab => restored.open(tab.url, tab.title));
    restored.select(restored.tabs.find(tab => tab.url === saved.activeUrl).id);
    assert.equal(restored.tabs.length, 2);
    assert.equal(restored.active, 'initial');
    let requests = 0;
    const deferred = {dataset: {src: 'https://pos.example/dashboard/product?_workspace=frame'}, setAttribute() {}, set src(value) { requests++; }};
    const background = {dataset: {src: 'https://pos.example/dashboard/customer?_workspace=frame'}};
    assert.equal(requests, 0);
    assert.equal(loadWorkspaceFrame(deferred), true);
    assert.equal(requests, 1);
    assert.equal(loadWorkspaceFrame(deferred), false);
    assert.equal(requests, 1, 'Switching back loaded the same page again');
    assert.equal(background.dataset.started, undefined, 'Background tab loaded prematurely');
    console.log('WORKSPACE_OK');
    JAVASCRIPT;
    $process = new Process(['node', '--input-type=module', '-e', $script], dirname(__DIR__, 2));
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('WORKSPACE_OK');
});
