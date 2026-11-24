<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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
        // Shown only while a point of sale tab hides the side menu.
        $response->assertSee('workspace-menu workspace-menu-pos-only', false)->assertSee('data-tenant-shell', false);
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

test('the point of sale tab opens sections from «فتح قسم» because it hides the side menu', function (): void {
    $this->get('/dashboard')->assertSuccessful()
        ->assertSee('فتح قسم')
        ->assertSee('data-tenant-navigation-trigger', false);

    $css = file_get_contents(resource_path('css/workspace-tabs.css'));
    expect($css)->toContain('body.workspace-pos-active > [data-flux-sidebar],')
        ->and($css)->toContain('.workspace-menu.workspace-menu-pos-only { display: none; }')
        ->and($css)->toContain('body.workspace-pos-active .workspace-menu.workspace-menu-pos-only { display: block; }')
        ->and($css)->toContain('body.workspace-pos-active [data-tenant-navigation-trigger] { display: none !important; }');
});

test('the reports tab hides the side menu on computer screens and opens sections from «فتح قسم»', function (): void {
    $css = file_get_contents(resource_path('css/workspace-tabs.css'));
    $wide = Str::after($css, '@media screen and (min-width: 1024px) {'."\n".'    body.workspace-wide-active');

    expect($wide)->toContain('[data-flux-sidebar]')
        ->and($css)->toContain('body.workspace-wide-active .workspace-menu.workspace-menu-pos-only { display: block; }')
        ->and($css)->toContain('body.workspace-wide-active [data-tenant-navigation-trigger] { display: none !important; }')
        ->and(file_get_contents(resource_path('js/workspace-tabs.js')))->toContain("classList.toggle('workspace-wide-active', workspaceUsesWideLayout(activeUrl))");
});
