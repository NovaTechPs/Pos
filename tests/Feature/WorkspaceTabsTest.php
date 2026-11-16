<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر التبويبات']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الرئيسي']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
});

test('tenant pages expose one accessible workspace without duplicating the livewire page', function (string $path): void {
    $response = $this->get($path)->assertSuccessful()->assertSee('data-workspace-tabs', false)
        ->assertSee('role="tablist"', false)->assertSee('data-workspace-loading', false);
    if ($path === '/dashboard/pos') {
        $response->assertSee('فتح قسم')->assertDontSee('data-tenant-shell', false);
    } else {
        $response->assertDontSee('فتح قسم')->assertSee('data-tenant-shell', false);
    }
    $html = $response->getContent();
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@data-workspace-tabs]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-workspace-initial]')->length)->toBe(1);
    if ($path !== '/dashboard/pos') {
        expect($xpath->query('//body/*[@data-workspace-tabs and @data-flux-main]')->length)->toBe(1);
    } else {
        expect($xpath->query('//*[@data-workspace-tabs and @data-flux-main]')->length)->toBe(0);
    }
})->with(['/dashboard', '/dashboard/pos', '/dashboard/product', '/dashboard/reports']);

test('framed pages keep their normal permissions and omit nested workspace controls', function (): void {
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/dashboard/product')->assertSuccessful()
        ->assertDontSee('data-workspace-tabs', false)->assertSee('workspace-frame-body')->assertDontSee('data-flux-sidebar', false)
        ->assertDontSee('data-tenant-shell', false);
    auth()->user()->update(['is_owner' => false]);
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/dashboard/product')->assertForbidden();
});

test('a legacy frame marker in a normal page address no longer removes its navigation', function (): void {
    $this->withHeader('Sec-Fetch-Dest', 'document')->get('/dashboard/product?_workspace=frame')
        ->assertSuccessful()->assertSee('data-workspace-tabs', false)->assertSee('data-flux-sidebar', false);
});

test('reports retain frame detection when a request has no fetch destination header', function (): void {
    $this->get('/dashboard/reports')->assertSuccessful()
        ->assertSee('data-workspace-frame-bootstrap', false)
        ->assertSee('window.self !== window.top', false);
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/dashboard/reports')->assertSuccessful()
        ->assertDontSee('data-workspace-tabs', false)->assertDontSee('data-flux-sidebar', false);
});

test('a page opened in a workspace tab without the iframe header can still scroll', function (): void {
    $styles = (string) file_get_contents(resource_path('css/workspace-tabs.css'));
    expect($styles)->toContain("html[data-tenant-shell]:not([data-workspace-frame]),\n    html:not([data-workspace-frame]) body[data-tenant-shell] {")
        ->and($styles)->not->toMatch('/^\s{4}body\[data-tenant-shell\]/m');

    $html = $this->get('/dashboard/product')->assertSuccessful()->assertSee('data-tenant-shell', false)
        ->assertSee('data-workspace-frame-bootstrap', false)->getContent();
    if ($previewPath = getenv('CODEX_FRAME_PREVIEW')) {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));
        $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);
        $html = preg_replace('/<link[^>]+(?:stylesheet|modulepreload)[^>]*>/i', '', $html);
        file_put_contents($previewPath, str_replace('</head>', '<style>'.$css.'</style></head>', $html));
    }
});
