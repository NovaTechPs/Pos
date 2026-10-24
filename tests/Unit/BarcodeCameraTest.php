<?php

use Symfony\Component\Process\Process;

test('camera lazily loads stops after one barcode and releases cancelled or failed streams', function (): void {
    $script = <<<'JS'
    import assert from 'node:assert/strict';
    import { barcodeCamera } from './resources/js/barcode-camera.js';
    let loads = 0, stopped = 0, controlsStopped = 0, callback;
    let resolveMedia;
    const scanned = [];
    const stream = { getTracks: () => [{stop() { stopped++; }}] };
    const env = { isSecureContext: true, navigator: {mediaDevices: {getUserMedia: async () => stream}}, setInterval: () => 1, clearInterval() {}, addEventListener() {}, removeEventListener() {}, document: {hidden: false} };
    const load = async () => { loads++; return {BrowserMultiFormatReader: class { async decodeFromStream(media, video, cb) { callback = cb; return {stop() { controlsStopped++; }}; } }}; };
    const camera = barcodeCamera(load, env);
    camera.$nextTick = async () => {};
    camera.$refs = {preview: {}};
    camera.$wire = {scanBarcode: value => scanned.push(value)};
    assert.equal(loads, 0);
    await camera.start();
    assert.equal(loads, 1);
    callback({getText: () => '001234'});
    callback({getText: () => '001234'});
    assert.deepEqual(scanned, ['001234']);
    assert.equal(stopped, 1);
    assert.equal(controlsStopped, 1);
    assert.equal(camera.open, false);
    env.navigator.mediaDevices.getUserMedia = () => new Promise(resolve => {resolveMedia = resolve;});
    const pending = camera.start();
    await new Promise(resolve => setImmediate(resolve));
    camera.close();
    resolveMedia(stream);
    await pending;
    assert.equal(stopped, 2);
    env.navigator.mediaDevices.getUserMedia = async () => {throw Object.assign(new Error(), {name:'NotAllowedError'});};
    await camera.start();
    assert.match(camera.error, /السماح/);
    env.isSecureContext = false;
    await camera.start();
    assert.match(camera.error, /HTTPS/);
    console.log('CAMERA_OK');
    JS;
    $process = new Process(['node', '--input-type=module', '-e', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('CAMERA_OK');
});
