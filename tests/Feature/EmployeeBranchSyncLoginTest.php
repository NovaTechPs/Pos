<?php

use App\Models\Branch;
use App\Models\BranchSyncDevice;
use App\Models\BranchSyncOperation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\BranchSyncCredentials;
use App\Services\EmployeeBranchSyncLogin;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['branch-sync.mode' => 'edge', 'branch-sync.server_url' => 'https://waslapos.app', 'branch-sync.token' => null]);
    Http::preventStrayRequests();
});

function employeeSyncProfile(int $userId = 100, int $branchId = 50): array
{
    return [
        'user' => ['id' => $userId, 'name' => 'Employee', 'email' => 'cashier'.$userId.'@example.test',
            'email_verified_at' => now()->toDateTimeString(), 'tenant_id' => 20, 'branch_id' => $branchId,
            'is_active' => true, 'is_owner' => false],
        'tenant' => ['id' => 20, 'name' => 'Shop', 'is_active' => true],
        'branch' => ['id' => $branchId, 'tenant_id' => 20, 'name' => 'Branch '.$branchId],
        'role' => ['id' => 30, 'tenant_id' => 20, 'name' => 'Cashier'],
        'permissions' => [['id' => 1, 'name' => 'pos.view', 'display_name' => 'POS', 'group' => 'pos']],
        'two_factor_required' => false,
        'branch_sync' => ['token' => 'branch-token-'.$branchId, 'tenant_id' => 20, 'branch_id' => $branchId],
    ];
}

function employeeSyncRequest(int $userId = 100, string $password = 'password123'): Request
{
    return Request::create('/login', 'POST', ['email' => 'cashier'.$userId.'@example.test', 'password' => $password]);
}

test('central login provisions a device for the employee branch ignoring a supplied branch', function (): void {
    config(['branch-sync.mode' => 'central']);
    $tenant = Tenant::query()->create(['name' => 'Shop']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Assigned branch']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'is_owner' => true, 'password' => 'password123']);
    $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123',
        'offline_device_name' => 'Till', 'branch_id' => 999]);
    $response->assertOk()->assertJsonPath('branch_sync.branch_id', $branch->id)->assertJsonMissingPath('user.password');
    expect(BranchSyncDevice::query()->sole()->branch_id)->toBe($branch->id)
        ->and(BranchSyncDevice::query()->sole()->token_hash)->toBe(hash('sha256', $response->json('branch_sync.token')));
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('central refuses offline enrollment without a branch or POS permission', function (bool $hasBranch): void {
    config(['branch-sync.mode' => 'central']);
    $tenant = Tenant::query()->create(['name' => 'Shop']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Branch']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $hasBranch ? $branch->id : null,
        'is_owner' => ! $hasBranch, 'password' => 'password123']);
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123', 'offline_device_name' => 'Till'])->assertForbidden();
    expect(BranchSyncDevice::query()->count())->toBe(0);
})->with([true, false]);

test('first online employee login imports the assigned branch permissions and encrypted credentials', function (): void {
    Http::fake(['waslapos.app/api/login' => Http::response(employeeSyncProfile())]);
    $user = app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest());
    expect($user->branch_id)->toBe(50)->and($user->tenant_id)->toBe(20)
        ->and($user->hasPermission('pos.view'))->toBeTrue()
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and(app(BranchSyncCredentials::class)->all())->toBe([['tenant_id' => 20, 'branch_id' => 50, 'token' => 'branch-token-50']]);
    expect(TenantSetting::query()->where('key', 'branch_sync_device_50')->value('value'))->not->toContain('branch-token-50');
    Http::assertSent(fn ($request) => $request->url() === 'https://waslapos.app/api/login'
        && $request['offline_device_name'] === 'Windows POS' && ! isset($request['branch_id']));
});

test('cached employee can log in offline but wrong passwords and uncached accounts cannot', function (): void {
    Http::fake(['waslapos.app/api/login' => Http::response(employeeSyncProfile())]);
    app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest());
    Http::swap(new Factory);
    Http::fake(['waslapos.app/api/login' => Http::failedConnection()]);
    expect(app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest())->branch_id)->toBe(50)
        ->and(app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest(password: 'wrong')))->toBeNull()
        ->and(app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest(999)))->toBeNull();
});

test('central rejection never falls back to a cached password', function (): void {
    Http::fake(['waslapos.app/api/login' => Http::response(employeeSyncProfile())]);
    app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest());
    Http::swap(new Factory);
    Http::fake(['waslapos.app/api/login' => Http::response(['message' => 'Disabled'], 403)]);
    expect(fn () => app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest()))->toThrow(ValidationException::class);
});

test('two factor accounts cannot bypass their second factor through offline login', function (): void {
    $profile = employeeSyncProfile();
    $profile['two_factor_required'] = true;
    Http::fake(['waslapos.app/api/login' => Http::response($profile)]);
    app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest());
    Http::swap(new Factory);
    Http::fake(['waslapos.app/api/login' => Http::failedConnection()]);
    expect(app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest()))->toBeNull();
});

test('employee changes branch online without losing the old branch upload credentials', function (): void {
    Http::fake(['waslapos.app/api/login' => Http::response(employeeSyncProfile())]);
    app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest());
    Http::swap(new Factory);
    Http::fake(['waslapos.app/api/login' => Http::response(employeeSyncProfile(branchId: 60))]);
    $user = app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest());
    expect($user->branch_id)->toBe(60)->and(app(BranchSyncCredentials::class)->all())->toHaveCount(2);
});

test('pending invoices use the credentials for their own branch after another employee logs in', function (): void {
    Http::fake(['waslapos.app/api/login' => Http::response(employeeSyncProfile())]);
    app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest());
    Http::swap(new Factory);
    Http::fake(['waslapos.app/api/login' => Http::response(employeeSyncProfile(101, 60))]);
    app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest(101));
    foreach ([50, 60] as $branchId) {
        BranchSyncOperation::query()->create(['tenant_id' => 20, 'branch_id' => $branchId,
            'operation_uuid' => (string) Str::uuid(), 'operation_type' => 'sale.upsert',
            'status' => 'pending', 'payload' => ['branch' => $branchId]]);
    }
    Http::swap(new Factory);
    Http::fake(function ($request) {
        $operation = $request['operations'][0];
        expect($request->header('Authorization')[0])->toBe('Bearer branch-token-'.$operation['payload']['branch']);

        return Http::response(['accepted' => [$operation['operation_uuid'] => 'synced']]);
    });
    $this->artisan('branch-sync:push')->assertSuccessful();
    expect(BranchSyncOperation::query()->where('status', 'synced')->count())->toBe(2);
    Http::assertSentCount(2);
});

test('inconsistent central branch metadata cannot overwrite the local database', function (): void {
    $profile = employeeSyncProfile();
    $profile['branch']['tenant_id'] = 99;
    Http::fake(['waslapos.app/api/login' => Http::response($profile)]);
    expect(fn () => app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest()))->toThrow(ValidationException::class);
    expect(DB::table('users')->count())->toBe(0)->and(DB::table('tenants')->count())->toBe(0);
});

test('offline login form provides an optional central two factor code', function (): void {
    $this->get('/login')->assertOk()->assertSee('name="code"', false);
});

test('desktop login explains central rejection without disclosing server details', function (int $status, array $body, string $field, string $message): void {
    Http::fake(['waslapos.app/api/login' => Http::response($body, $status)]);

    try {
        app(EmployeeBranchSyncLogin::class)->authenticate(employeeSyncRequest());
        $this->fail('The rejected login must not authenticate.');
    } catch (ValidationException $exception) {
        expect($exception->errors()[$field][0])->toContain($message)
            ->and(User::query()->count())->toBe(0);
    }
})->with([
    'credentials' => [422, ['errors' => ['email' => ['Invalid credentials']]], 'email', 'البريد الإلكتروني أو كلمة المرور غير صحيحة'],
    'two factor' => [422, ['errors' => ['code' => ['Invalid code']]], 'code', 'رمز التحقق بخطوتين الصحيح'],
    'missing endpoint' => [404, ['message' => 'Not found'], 'email', 'نشر تحديث السيرفر أولًا'],
    'old server response' => [200, ['token' => 'mobile-only-token', 'user' => ['id' => 100]], 'email', 'لا يدعم تسجيل دخول نسخة سطح المكتب'],
    'inactive account' => [403, ['message' => 'Your account is inactive.'], 'email', 'حساب الموظف موقوف'],
    'missing branch' => [403, ['message' => 'A POS employee must be assigned to a branch.'], 'email', 'ربط الموظف بفرع'],
    'rate limit' => [429, ['message' => 'Too many attempts'], 'email', 'انتظر دقيقة'],
]);

test('the web login uses the central employee branch automatically', function (): void {
    Http::fake(['waslapos.app/api/login' => Http::response(employeeSyncProfile()),
        'waslapos.app/api/branch-sync/catalog*' => Http::response([
            'tenant_id' => 20, 'branch_id' => 50, 'rows' => [], 'next' => null,
            'categories' => [], 'barcodes' => [], 'prices' => [],
        ])]);
    $this->post('/login', ['email' => 'cashier100@example.test', 'password' => 'password123'])->assertRedirect();
    $this->assertAuthenticatedAs(User::query()->findOrFail(100));
    expect(auth()->user()->branch_id)->toBe(50);
});

test('a POS employee needs checkout permission before obtaining an upload credential', function (): void {
    config(['branch-sync.mode' => 'central']);
    $tenant = Tenant::query()->create(['name' => 'Shop']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Assigned branch']);
    $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Cashier']);
    $view = Permission::query()->create(['name' => 'pos.view', 'display_name' => 'POS', 'group' => 'pos']);
    $role->permissions()->attach($view);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'role_id' => $role->id, 'is_owner' => false, 'password' => 'password123']);
    $login = ['email' => $user->email, 'password' => 'password123', 'offline_device_name' => 'Till'];
    $this->postJson('/api/login', $login)->assertForbidden();
    $checkout = Permission::query()->create(['name' => 'pos.sale.checkout', 'display_name' => 'Checkout', 'group' => 'pos']);
    $role->permissions()->attach($checkout);
    $this->postJson('/api/login', $login)->assertOk()->assertJsonPath('branch_sync.branch_id', $branch->id);
});
