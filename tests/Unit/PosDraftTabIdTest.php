<?php

use Symfony\Component\Process\Process;

test('each tab keeps a stable draft id in window.name and workspace tabs save their frame names', function (): void {
    $script = <<<'JS'
    const fs = require('node:fs');
    const vm = require('node:vm');
    const assert = require('node:assert/strict');
    const draftSource = fs.readFileSync('resources/js/pos-draft-tab.js', 'utf8').replace('export function', 'function');
    const firstTab = { name: '' };
    vm.runInNewContext(draftSource, { window: firstTab, crypto: globalThis.crypto });
    const id = firstTab.posDraftTabId();
    assert.match(id, /^pos-[A-Za-z0-9]{20}$/);
    assert.equal(firstTab.posDraftTabId(), id, 'Refreshing the same tab keeps its id');

    const workspaceTab = { name: 'ws-abcdef123456' };
    vm.runInNewContext(draftSource, { window: workspaceTab, crypto: globalThis.crypto });
    assert.equal(workspaceTab.posDraftTabId(), 'ws-abcdef123456', 'A workspace frame uses its saved frame name');

    const otherTab = { name: 'my-window' };
    vm.runInNewContext(draftSource, { window: otherTab, crypto: globalThis.crypto });
    assert.notEqual(otherTab.posDraftTabId(), id, 'Another tab gets its own id');

    (async () => {
        const source = fs.readFileSync('resources/js/workspace-tabs.js', 'utf8');
        const { readWorkspaceSnapshot, newWorkspaceFrameName } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
        const base = 'https://pos.example/dashboard/pos';
        const snapshot = readWorkspaceSnapshot(JSON.stringify({ tabs: [
            { url: base, title: 'POS', frameName: 'ws-keepme123456' },
            { url: base, title: 'POS 2', frameName: 'bad name' },
        ] }), base);
        assert.equal(snapshot.tabs[0].frameName, 'ws-keepme123456');
        assert.equal(snapshot.tabs[1].frameName, undefined, 'Invalid saved names are ignored');
        assert.match(newWorkspaceFrameName(), /^ws-[A-Za-z0-9]{20}$/);
        console.log('DRAFT_TAB_OK');
    })().catch((error) => { console.error(error); process.exit(1); });
    JS;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('DRAFT_TAB_OK');
});
