<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Desktop shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Desktop branch']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
});

test('desktop opens back-office pages from the central server', function (string $path): void {
    config(['app.desktop' => true, 'branch-sync.mode' => 'edge']);

    $this->get($path.'?_workspace=frame')->assertOk()
        ->assertSee('data-desktop-central-page', false)
        ->assertSee('data-central-path="'.$path.'"', false)
        ->assertDontSee('data-central-local', false);
})->with(['/dashboard/purchase-invoices', '/dashboard/returns', '/dashboard/receipts', '/dashboard/reports', '/dashboard/product']);

test('desktop sales invoices open centrally with a local list for offline use', function (): void {
    config(['app.desktop' => true, 'branch-sync.mode' => 'edge']);

    $this->get('/dashboard/sale-invoices?search=SAL-1')->assertOk()
        ->assertSee('data-central-path="/dashboard/sale-invoices?search=SAL-1"', false)
        ->assertSee('data-central-local', false);
    $this->get('/dashboard/sale-invoices?local=1')->assertOk()->assertDontSee('data-desktop-central-page', false);
});

test('the point of sale and barcode printing stay on the device', function (string $path): void {
    config(['app.desktop' => true, 'branch-sync.mode' => 'edge']);

    $this->get($path)->assertOk()->assertDontSee('data-desktop-central-page', false);
})->with(['/dashboard/pos', '/dashboard/barcode/printer']);

test('the web app and branch servers are not redirected', function (bool $desktop, string $mode): void {
    config(['app.desktop' => $desktop, 'branch-sync.mode' => $mode]);

    $this->get('/dashboard/purchase-invoices')->assertOk()->assertDontSee('data-desktop-central-page', false);
})->with([[false, 'central'], [false, 'edge']]);
