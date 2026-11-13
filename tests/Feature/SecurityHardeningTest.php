<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Secure shop', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true, 'is_active' => true]);
});

test('pages and API replies carry the browser security headers', function (): void {
    $page = $this->get('http://localhost/login');
    $page->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->getJson('/api/theme')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

test('when an idle limit is set, an app sign-in left unused longer stops working', function (): void {
    // Off by default: app sign-ins stay active until logout unless API_TOKEN_IDLE_DAYS is set.
    expect(config('auth.api_token_idle_days'))->toBe(0);
    config(['auth.api_token_idle_days' => 30]);
    $token = $this->owner->createToken('mobile-app')->plainTextToken;
    $call = fn () => $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/theme');

    $call()->assertOk();

    DB::table('personal_access_tokens')->update(['last_used_at' => now()->subDays(31)]);
    $this->app['auth']->forgetGuards();
    $call()->assertUnauthorized();

    config(['auth.api_token_idle_days' => 0]);
    $this->app['auth']->forgetGuards();
    $call()->assertOk();
});

test('deactivating or deleting an account removes its app sign-ins', function (): void {
    $employee = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    $employee->createToken('mobile-app');
    $employee->createToken('desktop');
    $this->owner->createToken('mobile-app');

    $employee->update(['name' => 'تغيير الاسم لا يلغي الدخول']);
    expect($employee->tokens()->count())->toBe(2);

    $employee->update(['is_active' => false]);
    expect($employee->tokens()->count())->toBe(0)
        ->and($this->owner->tokens()->count())->toBe(1);

    $this->owner->delete();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});
