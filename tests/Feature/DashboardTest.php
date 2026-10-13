<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('tenant.dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create([
        'type' => 'tenant_user',
        'is_owner' => true,
    ]);
    $this->actingAs($user);

    $response = $this->get(route('tenant.dashboard'));
    $response->assertOk();
});

test('tenant users without the dashboard permission are denied', function () {
    $user = User::factory()->create([
        'type' => 'tenant_user',
        'is_owner' => false,
    ]);
    $this->actingAs($user);

    $this->get(route('tenant.dashboard'))->assertForbidden();
});
