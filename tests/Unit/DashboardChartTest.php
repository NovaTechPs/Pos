<?php

use Symfony\Component\Process\Process;

test('dashboard charts load Chart.js lazily, right to left, open the linked report on click and clean up', function (): void {
    $script = <<<'JS'
    import assert from 'node:assert/strict';
    import { dashboardChart } from './resources/js/dashboard-charts.js';
    globalThis.document = { body: {} };
    globalThis.getComputedStyle = () => ({ fontFamily: 'Tajawal' });
    const navigated = [];
    globalThis.window = { Livewire: { navigate: (url) => navigated.push(url) } };
    let created, destroyed = 0, loads = 0;
    class Chart {
        static defaults = { font: {} };
        static register() {}
        constructor(canvas, config) { created = { canvas, config }; }
        destroy() { destroyed++; }
    }
    const component = dashboardChart(
        { type: 'bar', data: { labels: ['A', 'B'] }, options: { plugins: { legend: { display: false } } }, links: ['/reports?a', null] },
        async () => { loads++; return { Chart, registerables: [] }; },
    );
    component.$refs = { canvas: 'canvas-element' };
    assert.equal(loads, 0);
    await component.init();
    assert.equal(loads, 1);
    assert.equal(created.canvas, 'canvas-element');
    assert.equal(created.config.links, undefined);
    assert.equal(created.config.options.plugins.legend.rtl, true);
    assert.equal(created.config.options.plugins.legend.display, false);
    assert.equal(created.config.options.maintainAspectRatio, false);
    assert.equal(Chart.defaults.font.family, 'Tajawal');
    created.config.options.onClick({}, [{ index: 1 }]);
    created.config.options.onClick({}, [{ index: 0 }]);
    assert.deepEqual(navigated, ['/reports?a']);
    component.destroy();
    component.destroy();
    assert.equal(destroyed, 1);
    console.log('CHART_OK');
    JS;
    $process = new Process(['node', '--input-type=module', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('CHART_OK');
});
