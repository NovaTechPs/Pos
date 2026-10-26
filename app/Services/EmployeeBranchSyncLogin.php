<?php

namespace App\Services;

use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeeBranchSyncLogin
{
    public function authenticate(Request $request): ?User
    {
        $serverUrl = rtrim((string) config('branch-sync.server_url'), '/');
        if (parse_url($serverUrl, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['email' => 'عنوان خادم المزامنة يجب أن يستخدم HTTPS.']);
        }
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->post($serverUrl.'/api/login', [
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'code' => $request->input('code'),
                'offline_device_name' => 'Windows POS',
            ]);
        } catch (ConnectionException) {
            return $this->cachedUser($request);
        }
        if ($response->serverError()) {
            return $this->cachedUser($request);
        }
        if (! $response->successful()) {
            if ($response->status() === 422 && $response->json('errors.code')) {
                throw ValidationException::withMessages(['code' => 'أدخل رمز التحقق بخطوتين الصحيح لحسابك على الموقع الرئيسي.']);
            }

            $message = match ($response->status()) {
                401, 422 => 'البريد الإلكتروني أو كلمة المرور غير صحيحة. استخدم حساب الموظف الموجود على الموقع الرئيسي.',
                403 => match ($response->json('message')) {
                    'Your account is inactive.' => 'حساب الموظف موقوف على الموقع الرئيسي. راجع مدير المتجر.',
                    'Your tenant is inactive.' => 'المتجر موقوف على الموقع الرئيسي. راجع مدير المتجر.',
                    'A POS employee must be assigned to a branch.' => 'يجب ربط الموظف بفرع ومنحه صلاحيات نقطة البيع وإتمام البيع على الموقع الرئيسي.',
                    default => 'رفض الخادم صلاحية الدخول. تأكد من تفعيل الحساب وربطه بفرع ومنحه صلاحية البيع.',
                },
                404, 405 => 'تحديث المزامنة غير متاح على الخادم الرئيسي. اطلب من مسؤول النظام نشر تحديث السيرفر أولًا.',
                429 => 'محاولات تسجيل الدخول كثيرة. انتظر دقيقة ثم حاول مرة أخرى.',
                default => 'تعذر تسجيل الدخول إلى الخادم الرئيسي. حاول لاحقًا أو راجع مسؤول النظام.',
            };
            throw ValidationException::withMessages(['email' => $message]);
        }
        $data = $response->json();
        if (! is_array($data) || ! is_string(data_get($data, 'branch_sync.token'))) {
            throw ValidationException::withMessages([
                'email' => 'الخادم الرئيسي لا يدعم تسجيل دخول نسخة سطح المكتب بعد. اطلب من مسؤول النظام نشر تحديث السيرفر أولًا.',
            ]);
        }
        Validator::make($data, [
            'user.id' => ['required', 'integer', 'min:1'],
            'user.name' => ['required', 'string'],
            'user.email' => ['required', 'email'],
            'user.tenant_id' => ['required', 'integer', 'min:1'],
            'user.branch_id' => ['required', 'integer', 'min:1'],
            'user.is_active' => ['required', 'accepted'],
            'user.is_owner' => ['required', 'boolean'],
            'tenant.id' => ['required', 'same:user.tenant_id'],
            'tenant.name' => ['required', 'string'],
            'tenant.is_active' => ['required', 'accepted'],
            'branch.id' => ['required', 'same:user.branch_id'],
            'branch.tenant_id' => ['required', 'same:user.tenant_id'],
            'branch.name' => ['required', 'string'],
            'role' => ['present', 'nullable', 'array'],
            'permissions' => ['present', 'array'],
            'two_factor_required' => ['required', 'boolean'],
            'branch_sync.token' => ['required', 'string'],
            'branch_sync.tenant_id' => ['required', 'same:user.tenant_id'],
            'branch_sync.branch_id' => ['required', 'same:user.branch_id'],
        ])->validate();
        if (strcasecmp($data['user']['email'], $request->string('email')->toString()) !== 0) {
            throw ValidationException::withMessages(['email' => 'استجابة تسجيل الدخول لا تطابق الحساب.']);
        }

        return DB::transaction(function () use ($data, $request): User {
            $tenantId = (int) $data['user']['tenant_id'];
            $userId = (int) $data['user']['id'];
            $existing = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if ($existing && ((int) $existing->tenant_id !== $tenantId || strcasecmp($existing->email, $data['user']['email']) !== 0)) {
                throw ValidationException::withMessages(['email' => 'معرف الموظف يتعارض مع حساب محلي آخر.']);
            }
            DB::table('tenants')->updateOrInsert(['id' => $tenantId], Arr::only($data['tenant'], ['name', 'phone', 'is_active']));
            $branch = DB::table('branches')->where('id', $data['branch']['id'])->lockForUpdate()->first();
            if ($branch && (int) $branch->tenant_id !== $tenantId) {
                throw ValidationException::withMessages(['email' => 'معرف الفرع يتعارض مع متجر محلي آخر.']);
            }
            DB::table('branches')->updateOrInsert(['id' => $data['branch']['id']],
                Arr::only($data['branch'], ['tenant_id', 'name', 'phone', 'address', 'type']));
            $roleId = null;
            if ($data['role'] !== null) {
                $role = $data['role'];
                $existingRole = DB::table('roles')->where('id', $role['id'])->lockForUpdate()->first();
                if ((int) $role['tenant_id'] !== $tenantId || ($existingRole && (int) $existingRole->tenant_id !== $tenantId)) {
                    throw ValidationException::withMessages(['email' => 'صلاحيات الحساب لا تطابق المتجر.']);
                }
                $roleId = $role['id'];
                DB::table('roles')->updateOrInsert(['id' => $roleId], Arr::only($role, ['tenant_id', 'name', 'description']));
                $permissionIds = [];
                foreach ($data['permissions'] as $permission) {
                    DB::table('permissions')->updateOrInsert(['name' => $permission['name']], Arr::only($permission, ['display_name', 'group']));
                    $permissionIds[] = DB::table('permissions')->where('name', $permission['name'])->value('id');
                }
                DB::table('permission_role')->where('role_id', $roleId)->delete();
                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
            DB::table('users')->updateOrInsert(['id' => $userId], Arr::only($data['user'], [
                'name', 'email', 'email_verified_at', 'tenant_id', 'branch_id', 'is_active', 'is_owner',
            ]) + ['role_id' => $roleId, 'type' => 'tenant_user', 'deleted_at' => null,
                'password' => Hash::make($request->string('password')->toString())]);
            app(BranchSyncCredentials::class)->remember($tenantId, (int) $data['user']['branch_id'], $data['branch_sync']['token']);
            TenantSetting::query()->updateOrCreate(['tenant_id' => $tenantId, 'key' => 'branch_sync_employee_'.$userId], [
                'value' => Crypt::encryptString(json_encode([
                    'two_factor_required' => $data['two_factor_required'],
                    'server_url' => rtrim((string) config('branch-sync.server_url'), '/'),
                ], JSON_THROW_ON_ERROR)),
            ]);

            return User::query()->findOrFail($userId);
        });
    }

    private function cachedUser(Request $request): ?User
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();
        if (! $user || ! $user->canAccessApplication() || ! $user->branch_id || ! Hash::check($request->string('password')->toString(), $user->password)) {
            return null;
        }
        $profile = TenantSetting::query()->where('tenant_id', $user->tenant_id)->where('key', 'branch_sync_employee_'.$user->id)->value('value');
        if (! is_string($profile)) {
            return null;
        }
        $profile = json_decode(Crypt::decryptString($profile), true, flags: JSON_THROW_ON_ERROR);
        if ($profile['two_factor_required'] || $profile['server_url'] !== rtrim((string) config('branch-sync.server_url'), '/')) {
            return null;
        }

        return $user;
    }
}
