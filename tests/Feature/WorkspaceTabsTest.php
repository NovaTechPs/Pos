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
})->with(['/dashboard', '/dashboard/pos', '/dashboard/product']);

test('framed pages keep their normal permissions and omit nested workspace controls', function (): void {
    $this->get('/dashboard/product?_workspace=frame')->assertSuccessful()
        ->assertDontSee('data-workspace-tabs', false)->assertSee('workspace-frame-body')->assertDontSee('data-flux-sidebar', false);
    auth()->user()->update(['is_owner' => false]);
    $this->get('/dashboard/product?_workspace=frame')->assertForbidden();
});
