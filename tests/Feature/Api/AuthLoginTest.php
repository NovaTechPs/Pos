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
        'expires_at' => null,
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

test('app token stays valid after a year and explicit logout still revokes it', function (): void {
    $user = User::factory()->create(['tenant_id' => 1, 'is_active' => true, 'password' => 'Persistent-Password42!']);
    $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Persistent-Password42!'])
        ->assertOk()->json('token');
    expect($user->tokens()->sole()->expires_at)->toBeNull();
    $this->travel(400)->days();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/logout')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/employee/profile')->assertUnauthorized();
    $this->travelBack();
});

test('existing app tokens retain login without changing web sessions or other token expiries', function (): void {
    $user = User::factory()->create(['tenant_id' => 1, 'remember_token' => 'existing-web-remember-token']);
    $app = $user->createToken('mobile-app', ['*'], now()->addDays(30))->accessToken;
    $expired = $user->createToken('mobile-app', ['*'], now()->subDay())->accessToken;
    $integration = $user->createToken('integration', ['*'], now()->addDays(7))->accessToken;
    $revoked = $user->createToken('mobile-app', ['*'], now()->addDays(30))->accessToken;
    $revoked->delete();
    $webSettings = [config('session.lifetime'), config('session.expire_on_close')];
    (require database_path('migrations/2026_11_01_145646_keep_existing_app_tokens_signed_in.php'))->up();
    expect($app->fresh()->expires_at)->toBeNull()
        ->and($expired->fresh()->expires_at)->toBeNull()
        ->and($integration->fresh()->expires_at->equalTo($integration->expires_at))->toBeTrue()
        ->and($revoked->fresh())->toBeNull()
        ->and($user->fresh()->remember_token)->toBe('existing-web-remember-token')
        ->and([config('session.lifetime'), config('session.expire_on_close')])->toBe($webSettings);
});
