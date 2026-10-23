<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class ClientSetupSeeder extends Seeder
{
    public function run(): void
    {
        $data = Validator::make(config('client-setup'), [
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'tenant_name' => ['required', 'string', 'max:191'],
            'plan_slug' => ['required', 'string', 'max:191'],
            'branches' => ['required', 'array', 'min:1'],
            'branches.*' => ['required', 'string', 'distinct', 'max:191'],
            'users' => ['required', 'array', 'min:2'],
            'users.*.name' => ['required', 'string', 'max:191'],
            'users.*.email' => ['required', 'email', 'distinct:ignore_case', 'max:191'],
            'users.*.role' => ['required', 'in:system_admin,owner,admin,cashier,wholesale_employee'],
            'users.*.branch' => ['required_unless:users.*.role,system_admin', 'nullable', 'integer', 'min:0'],
        ])->validate();

        $roles = array_column($data['users'], 'role');
        if (count(array_filter($roles, fn (string $role): bool => $role === 'owner')) !== 1) {
            throw new RuntimeException('Configure exactly one tenant owner in client-setup.users.');
        }
        foreach ($data['users'] as $user) {
            if ($user['role'] !== 'system_admin' && ! array_key_exists($user['branch'], $data['branches'])) {
                throw new RuntimeException('A configured user refers to an unknown branch.');
            }
        }

        DB::transaction(function () use ($data): void {
            $this->call(PermissionSeeder::class);
            $plan = Plan::query()->firstOrCreate(['slug' => $data['plan_slug']], ['name' => 'خطة العميل', 'price' => 30]);
            foreach (['pos_system' => '1', 'products_limit' => '-1'] as $key => $value) {
                PlanFeature::query()->firstOrCreate(['plan_id' => $plan->id, 'feature_key' => $key], ['value' => $value]);
            }

            $tenant = Tenant::query()->firstOrCreate(['name' => $data['tenant_name']], ['plan_id' => $plan->id, 'is_active' => true]);
            $branches = [];
            foreach ($data['branches'] as $index => $name) {
                $branches[$index] = Branch::withTrashed()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => $name], ['type' => 'branch']);
                if ($branches[$index]->trashed()) {
                    throw new RuntimeException('A configured branch has been deleted. Restore it before seeding.');
                }
            }

            $roleModels = [];
            foreach ($this->rolePermissions() as $name => $permissions) {
                $role = Role::query()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => $name], ['description' => $name]);
                if ($role->wasRecentlyCreated) {
                    $role->permissions()->sync(Permission::query()->when($permissions !== null, fn ($query) => $query->whereIn('name', $permissions))->pluck('id'));
                }
                $roleModels[$name] = $role;
            }

            foreach ($data['users'] as $account) {
                $isSystemAdmin = $account['role'] === 'system_admin';
                $isOwner = $account['role'] === 'owner';
                $user = User::withTrashed()->firstOrCreate(['email' => strtolower($account['email'])], [
                    'name' => $account['name'], 'password' => $data['password'],
                    'type' => $isSystemAdmin ? 'saas_admin' : 'tenant_user', 'is_owner' => $isOwner,
                    'is_active' => true, 'tenant_id' => $isSystemAdmin ? null : $tenant->id,
                    'branch_id' => $isSystemAdmin ? null : $branches[$account['branch']]->id,
                    'role_id' => $isSystemAdmin || $isOwner ? null : $roleModels[$account['role']]->id,
                ]);
                if ($user->trashed() || $user->isSaaSAdmin() !== $isSystemAdmin
                    || (! $isSystemAdmin && (int) $user->tenant_id !== (int) $tenant->id)
                    || ($isOwner && ! $user->isTenantOwner())) {
                    throw new RuntimeException('A configured email belongs to an incompatible existing account. No setup data was changed.');
                }
                if ($isOwner) {
                    if ($tenant->owner_id && (int) $tenant->owner_id !== (int) $user->id) {
                        throw new RuntimeException('The tenant already has a different owner. No setup data was changed.');
                    }
                    if (! $tenant->owner_id) {
                        $tenant->owner()->associate($user);
                        $tenant->save();
                    }
                }
            }
        });
    }

    /** @return array<string, list<string>|null> */
    private function rolePermissions(): array
    {
        return [
            'admin' => null,
            'cashier' => ['dashboard.view', 'pos.view', 'pos.product.search', 'pos.product.add', 'pos.product.remove',
                'pos.product.quantity', 'pos.sale.create', 'pos.sale.checkout', 'pos.sale.print', 'pos.shift.open',
                'pos.shift.close', 'pos.invoice.search', 'pos.invoice.hold', 'products.view', 'customers.view',
                'customers.create', 'returns.view', 'returns.create'],
            'wholesale_employee' => ['dashboard.view', 'wholesale_sales.view', 'wholesale_sales.create',
                'wholesale_sales.edit', 'wholesale_sales.print', 'van_sales.view', 'van_sales.create', 'van_sales.edit',
                'van_sales.print', 'products.view', 'customers.view', 'returns.view', 'returns.create'],
        ];
    }
}
