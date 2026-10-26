<?php

use App\Models\Tenant;
use App\Models\User;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('public pages advertise the app manifest and register the service worker', function (string $route): void {
    $this->get(route($route))->assertSuccessful()
        ->assertSee('rel="manifest"', false)
        ->assertSee('manifest.webmanifest')
        ->assertSee('name="theme-color"', false)
        ->assertSee('serviceWorker.register', false);
})->with(['home', 'login']);

test('authenticated dashboard advertises the same installable application', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر التطبيق', 'is_active' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $this->actingAs($user)->get(route('tenant.dashboard'))->assertSuccessful()
        ->assertSee('manifest.webmanifest')
        ->assertSee('sw.js');
});

test('manifest has a standalone launch URL and correctly sized local PNG icons', function (): void {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['name'])->toBe('WaslaPOS')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['scope'])->toBe('/')
        ->and($manifest['start_url'])->toBe('/dashboard')
        ->and($manifest['prefer_related_applications'])->toBeFalse();
    foreach ([192, 512] as $size) {
        $icon = collect($manifest['icons'])->firstWhere('sizes', $size.'x'.$size);
        expect($icon)->not->toBeNull();
        $image = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect($image[0])->toBe($size)->and($image[1])->toBe($size)->and($image['mime'])->toBe('image/png');
    }
});

test('service worker preserves network responses and handles offline navigation without caching private data', function (): void {
    $node = (new ExecutableFinder)->find('node');
    if (! $node) {
        $this->markTestSkipped('Node.js is required to execute the service worker behavior test.');
    }
    $script = <<<'JS'
const vm = require('node:vm');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
    const handlers = {};
    let activated = false;
    let installed = false;
    let networkRequest;
    const onlineResponse = new Response('live account data');
    const context = {
        self: {
            addEventListener: (name, callback) => handlers[name] = callback,
            skipWaiting: async () => installed = true,
            clients: { claim: async () => activated = true },
        },
        Response,
        fetch: async (request) => { networkRequest = request; return onlineResponse; },
    };
    vm.runInNewContext(fs.readFileSync('public/sw.js', 'utf8'), context);
    let pending;
    const lifecycle = { waitUntil: (promise) => pending = promise };
    handlers.install(lifecycle); await pending; assert.equal(installed, true);
    handlers.activate(lifecycle); await pending; assert.equal(activated, true);
    const request = { mode: 'navigate', method: 'GET', url: 'https://example.test/dashboard' };
    let response;
    handlers.fetch({ request, respondWith: (promise) => response = promise });
    assert.equal(await response, onlineResponse);
    assert.equal(networkRequest, request);
    context.fetch = async () => { throw new Error('offline'); };
    handlers.fetch({ request, respondWith: (promise) => response = promise });
    const offlineResponse = await response;
    assert.equal(offlineResponse.status, 503);
    assert.equal(offlineResponse.headers.get('Cache-Control'), 'no-store');
    assert.match(await offlineResponse.text(), /WaslaPOS/);
    for (const ignored of [{ mode: 'cors', method: 'GET' }, { mode: 'navigate', method: 'POST' }]) {
        handlers.fetch({ request: ignored, respondWith: () => assert.fail('API and write requests must remain untouched') });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
JS;
    $process = new Process([$node, '-e', $script], base_path());
    $process->mustRun();
    expect($process->isSuccessful())->toBeTrue();
});
