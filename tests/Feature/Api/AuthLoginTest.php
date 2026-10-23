<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('employee can login successfully with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'employee@waslapos.test',
        'password' => bcrypt('password123'),
        'is_active' => true,
        'tenant_id' => 1,
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'employee@waslapos.test',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'token',
            'token_type',
            'user' => [
                'id',
                'name',
                'email',
            ],
        ]);

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'mobile-app',
    ]);
});

test('employee cannot login with incorrect password', function () {
    User::factory()->create([
        'email' => 'employee@waslapos.test',
        'password' => bcrypt('password123'),
        'is_active' => true,
        'tenant_id' => 1,
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'employee@waslapos.test',
        'password' => 'wrongpassword',
    ]);

    $response->assertStatus(422);
});

test('protected api routes require a bearer token', function () {
    $this->getJson('/api/customers')->assertUnauthorized();
});

test('api bearer token can be revoked on logout', function () {
    $user = User::factory()->create([
        'email' => 'token@waslapos.test',
        'password' => bcrypt('password123'),
        'tenant_id' => 1,
        'is_active' => true,
    ]);

    $token = $this->postJson('/api/login', [
        'email' => 'token@waslapos.test',
        'password' => 'password123',
    ])->json('token');

    expect($token)->toBeString();

    $this->withToken($token)->postJson('/api/logout')->assertOk();
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'mobile-app',
    ]);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/employee/profile')->assertUnauthorized();
});

test('inactive employee cannot login', function () {
    User::factory()->create([
        'email' => 'inactive@waslapos.test',
        'password' => bcrypt('password123'),
        'is_active' => false,
        'tenant_id' => 1,
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'inactive@waslapos.test',
        'password' => 'password123',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Your account is inactive.',
        ]);
});
