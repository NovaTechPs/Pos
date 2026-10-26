<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchSyncDevice;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'offline_device_name' => ['sometimes', 'required', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        if (! $user->tenant_id) {
            return response()->json(['message' => 'Your account is not linked to a tenant.'], 403);
        }

        abort_unless($user->canAccessApplication(), 403, 'Your tenant is inactive.');

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            if (empty($validated['code']) || ! app(TwoFactorAuthenticationProvider::class)->verify(
                decrypt($user->two_factor_secret), $validated['code'],
            )) {
                throw ValidationException::withMessages(['code' => __('A valid two-factor authentication code is required.')]);
            }
        }

        $user->load(['role', 'branch']);
        if (isset($validated['offline_device_name'])) {
            abort_unless(config('branch-sync.mode') === 'central', 403);
            abort_unless($user->hasPermission('pos.view') && $user->hasPermission('pos.sale.checkout') && $user->branch
                && (int) $user->branch->tenant_id === (int) $user->tenant_id, 403, 'A POS employee must be assigned to a branch.');
            $plainTextToken = Str::random(64);
            BranchSyncDevice::query()->create([
                'device_uuid' => (string) Str::uuid(),
                'tenant_id' => $user->tenant_id,
                'branch_id' => $user->branch_id,
                'name' => $validated['offline_device_name'],
                'token_hash' => hash('sha256', $plainTextToken),
            ]);

            return response()->json([
                'user' => $user->only(['id', 'name', 'email', 'email_verified_at', 'tenant_id', 'branch_id', 'is_owner', 'is_active']),
                'tenant' => $user->tenant->only(['id', 'name', 'phone', 'is_active']),
                'branch' => $user->branch->only(['id', 'tenant_id', 'name', 'phone', 'address', 'type']),
                'role' => $user->role && (int) $user->role->tenant_id === (int) $user->tenant_id
                    ? $user->role->only(['id', 'tenant_id', 'name', 'description']) : null,
                'permissions' => $user->role && (int) $user->role->tenant_id === (int) $user->tenant_id
                    ? $user->role->permissions()->get(['permissions.id', 'name', 'display_name', 'group']) : [],
                'two_factor_required' => (bool) ($user->two_factor_secret && $user->two_factor_confirmed_at),
                'branch_sync' => ['token' => $plainTextToken, 'tenant_id' => $user->tenant_id, 'branch_id' => $user->branch_id],
            ]);
        }
        $employee = Employee::query()->where('user_id', $user->id)
            ->where(function ($query) use ($user): void {
                $query->where('tenant_id', $user->tenant_id)
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('tenant_id', $user->tenant_id));
            })->first();
        $todayAttendance = $employee
            ? EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', today())
                ->first()
            : null;
        $employeeProfile = $employee ? [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
        ] : null;
        $token = $user->createToken('mobile-app', ['*'], now()->addDays(30));

        return response()->json([
            'message' => 'Login successful',
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $user,
            'employee' => $employeeProfile,
            'today_attendance' => $todayAttendance,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
