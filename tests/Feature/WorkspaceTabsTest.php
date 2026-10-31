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
        ->assertSee('role="tablist"', false)->assertSee('فتح قسم')->assertSee('data-workspace-loading', false);
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
        ->assertDontSee('data-workspace-tabs', false)->assertSee('workspace-frame-body')->assertDontSee('data-flux-sidebar', false);
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
