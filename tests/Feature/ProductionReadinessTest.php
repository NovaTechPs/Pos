<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SystemBackupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Security tenant', 'is_active' => true]);
});

test('inactive accounts cannot authenticate on the web', function (): void {
    $user = User::factory()->create(['is_active' => false]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('inactive tenants cannot authenticate on the web or api', function (): void {
    $this->tenant->update(['is_active' => false]);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
    expect($user->tokens()->count())->toBe(0);
});

test('existing web sessions are rejected when accounts or tenants are disabled', function (string $target): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    if ($target === 'user') {
        $user->update(['is_active' => false]);
    } else {
        $this->tenant->update(['is_active' => false]);
    }
    $this->actingAs($user)->get(route('profile.edit'))->assertForbidden();
    $this->assertGuest();
})->with(['user', 'tenant']);

test('two factor api login requires a valid code before issuing a token', function (): void {
    $user = User::factory()->withTwoFactor()->create(['tenant_id' => $this->tenant->id]);
    $credentials = ['email' => $user->email, 'password' => 'password'];
    $provider = $this->mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->once()->with('secret', '000000')->andReturn(false);
    $provider->shouldReceive('verify')->once()->with('secret', '123456')->andReturn(true);
    $this->postJson('/api/login', $credentials)->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/login', $credentials + ['code' => '000000'])->assertUnprocessable();
    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/login', $credentials + ['code' => '123456'])->assertOk()->assertJsonStructure(['token']);
    expect($user->tokens()->count())->toBe(1);
});

test('tenant owners cannot download system backups even through a livewire action', function (): void {
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true]);
    Livewire::actingAs($owner)->test('pages::tenant.system.backup')->assertForbidden();
});

test('system administrator can download a backup', function (): void {
    $admin = User::factory()->create(['type' => 'saas_admin']);
    Storage::fake('local');
    $path = Storage::disk('local')->path('test-system-backup.zip');
    file_put_contents($path, 'test archive');
    $this->mock(SystemBackupService::class)->shouldReceive('create')->once()->andReturn($path);
    Livewire::actingAs($admin)->test('pages::tenant.system.backup')
        ->call('downloadBackup')->assertFileDownloaded();
    @unlink($path);
});

test('view only roles cannot change roles or branches', function (string $component, string $permission, string $action): void {
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Read only']);
    $role->permissions()->attach(Permission::query()->create(['name' => $permission, 'display_name' => $permission, 'group' => 'security'])->id);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id, 'is_owner' => false]);
    $this->withSession(['active_tenant_id' => $this->tenant->id]);
    $test = Livewire::actingAs($user)->test($component);
    if ($action === 'delete' || $action === 'edit') {
        $test->call($action, 999)->assertForbidden();
    } else {
        $test->call($action)->assertForbidden();
    }
})->with([
    ['pages::tenant.roles.index', 'roles.view', 'save'],
    ['pages::tenant.roles.index', 'roles.view', 'delete'],
    ['pages::tenant.roles.index', 'roles.view', 'edit'],
    ['pages::tenant.branches.index', 'branches.view', 'save'],
    ['pages::tenant.branches.index', 'branches.view', 'delete'],
    ['pages::tenant.categories.index', 'categories.view', 'save'],
    ['pages::tenant.products.index', 'products.view', 'save'],
    ['pages::tenant.products.index', 'products.view', 'saveCategory'],
    ['pages::tenant.products.index', 'products.view', 'saveOffer'],
    ['pages::tenant.customers.index', 'customers.view', 'save'],
    ['pages::tenant.employees.index', 'employees.view', 'save'],
    ['pages::tenant.accounting.payment', 'accounting.voucher.view', 'savePayment'],
    ['pages::tenant.accounting.receipts', 'accounting.receipt.view', 'saveReceipt'],
    ['pages::tenant.stock-transfers.index', 'stock_transfers.view', 'saveTransfer'],
    ['pages::tenant.sales.invoices', 'sales_invoices.view', 'saveInvoice'],
    ['pages::tenant.purchases.invoices', 'purchases.view', 'saveInvoice'],
    ['pages::tenant.wholesale.sales', 'wholesale_sales.view', 'completeSale'],
    ['pages::tenant.hr.payroll-manager', 'payroll.view', 'saveEmployee'],
    ['pages::tenant.hr.payroll-manager', 'payroll.view', 'createPeriod'],
    ['pages::tenant.hr.payroll-manager', 'payroll.view', 'saveAdvance'],
    ['pages::tenant.hr.payroll-manager', 'payroll.view', 'savePayment'],
    ['pages::tenant.hr.payroll-manager', 'payroll.view', 'approvePayroll'],
    ['pages::tenant.pos.sales', 'pos.view', 'openShift'],
    ['pages::tenant.pos.sales', 'pos.view', 'closeShift'],
    ['pages::tenant.pos.sales', 'pos.view', 'holdInvoice'],
]);

test('rerunning seeders preserves existing passwords', function (): void {
    $this->seed(DatabaseSeeder::class);
    $user = User::query()->where('email', 'user@example.com')->firstOrFail();
    $user->update(['password' => 'customer-chosen-password']);
    $this->seed(DatabaseSeeder::class);
    expect(Hash::check('customer-chosen-password', $user->fresh()->password))->toBeTrue();
});

test('production seeding creates permissions without demo accounts or tenants', function (): void {
    app()->instance('env', 'production');
    try {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        expect(User::query()->count())->toBe(0)
            ->and(Tenant::query()->count())->toBe(1)
            ->and(Permission::query()->count())->toBeGreaterThan(0);
    } finally {
        app()->instance('env', 'testing');
    }
});

test('backup includes uploaded product images and removes its temporary sql file', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('products/test.jpg', 'image bytes');
    config(['database.default' => 'mysql']);
    Process::fake(function ($process) {
        foreach ($process->command as $argument) {
            if (str_starts_with($argument, '--result-file=')) {
                file_put_contents(substr($argument, strlen('--result-file=')), '-- SQL backup');
            }
        }

        return Process::result(exitCode: 0);
    });
    $path = app(SystemBackupService::class)->create();
    try {
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue()
            ->and($zip->getFromName('database/backup.sql'))->toBe('-- SQL backup')
            ->and($zip->getFromName('storage/app/public/products/test.jpg'))->toBe('image bytes')
            ->and(file_exists(substr($path, 0, -4).'.sql'))->toBeFalse();
        $zip->close();
    } finally {
        unlink($path);
        config(['database.default' => 'sqlite']);
    }
});

test('system administrator provisioning never overwrites an existing account', function (): void {
    config(['provisioning.admin_password' => 'Initial-Admin-Password42!']);
    $this->artisan('app:create-system-administrator', ['email' => 'owner@example.test', '--no-interaction' => true])->assertSuccessful();
    $admin = User::query()->where('email', 'owner@example.test')->sole();
    expect($admin->isSaaSAdmin())->toBeTrue()->and(Hash::check('Initial-Admin-Password42!', $admin->password))->toBeTrue();
    config(['provisioning.admin_password' => 'Changed-Admin-Password42!']);
    $this->artisan('app:create-system-administrator', ['email' => 'owner@example.test', '--no-interaction' => true])->assertFailed();
    expect(Hash::check('Initial-Admin-Password42!', $admin->fresh()->password))->toBeTrue();
});

test('system administrator provisioning rejects missing or weak passwords', function (?string $password): void {
    config(['provisioning.admin_password' => $password]);
    $this->artisan('app:create-system-administrator', ['email' => 'owner@example.test', '--no-interaction' => true])->assertFailed();
    expect(User::query()->count())->toBe(0);
})->with([null, 'password123']);

test('failed database backup removes partially written files', function (): void {
    $directory = storage_path('app/private/backups');
    $before = glob($directory.'/*') ?: [];
    config(['database.default' => 'mysql']);
    Process::fake(function ($process) {
        foreach ($process->command as $argument) {
            if (str_starts_with($argument, '--result-file=')) {
                file_put_contents(substr($argument, strlen('--result-file=')), 'incomplete SQL');
            }
        }

        return Process::result(exitCode: 1);
    });
    try {
        expect(fn () => app(SystemBackupService::class)->create())->toThrow(RuntimeException::class);
        expect(glob($directory.'/*') ?: [])->toBe($before);
    } finally {
        config(['database.default' => 'sqlite']);
    }
});
