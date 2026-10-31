<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function requestedPasswordResetPath(): string
{
    return 'database/migrations/2026_10_31_222543_reset_requested_account_passwords.php';
}

test('requested reset changes every account password without changing account details', function (): void {
    $tenant = Tenant::create(['name' => 'Password reset tenant']);
    $users = collect([
        User::factory()->create(['type' => 'saas_admin', 'tenant_id' => null, 'password' => 'Previous-Admin42!', 'remember_token' => 'old-admin-token']),
        User::factory()->create(['type' => 'tenant_user', 'tenant_id' => $tenant->id, 'is_owner' => true, 'password' => 'Previous-Owner42!', 'remember_token' => 'old-owner-token']),
        User::factory()->create(['type' => 'tenant_user', 'tenant_id' => $tenant->id, 'is_owner' => false, 'is_active' => false, 'password' => 'Previous-Staff42!']),
    ]);
    $original = $users->map(fn (User $user) => $user->only(['id', 'email', 'name', 'tenant_id', 'branch_id', 'role_id', 'type', 'is_active', 'is_owner']));
    $migration = require base_path(requestedPasswordResetPath());
    $migration->up();
    foreach ($users as $index => $user) {
        $fresh = $user->fresh();
        expect(Hash::check('password', $fresh->password))->toBeTrue()
            ->and($fresh->password)->not->toBe('password')
            ->and($fresh->remember_token)->toBeNull()
            ->and($fresh->only(array_keys($original[$index])))->toBe($original[$index]);
    }
});

test('deployment migrations do not reset a password changed after the requested reset', function (): void {
    $user = User::factory()->create(['type' => 'saas_admin', 'tenant_id' => null, 'password' => 'Previous-Password42!']);
    DB::table('migrations')->where('migration', basename(requestedPasswordResetPath(), '.php'))->delete();
    $this->artisan('migrate', ['--path' => requestedPasswordResetPath(), '--force' => true])->assertSuccessful();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    $user->update(['password' => 'Changed-After-Reset42!']);
    $this->artisan('migrate', ['--path' => requestedPasswordResetPath(), '--force' => true])->assertSuccessful();
    expect(Hash::check('Changed-After-Reset42!', $user->fresh()->password))->toBeTrue();
});

test('requested reset also succeeds on a fresh database without accounts', function (): void {
    $migration = require base_path(requestedPasswordResetPath());
    $migration->up();
    expect(User::withTrashed()->count())->toBe(0);
});
