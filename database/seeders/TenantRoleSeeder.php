<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantRoleSeeder extends Seeder
{
    public function run(int $tenantId): void
    {
        Tenant::query()->whereKey($tenantId)->firstOrFail();
        foreach ($this->permissionsByRole() as $name => $permissions) {
            $role = Role::query()->firstOrCreate(['tenant_id' => $tenantId, 'name' => $name], ['description' => $name]);
            // Preserve permissions already customized by the customer.
            if ($role->wasRecentlyCreated) {
                $role->permissions()->sync(Permission::query()
                    ->when($permissions !== null, fn ($query) => $query->whereIn('name', $permissions))->pluck('id'));
            }
        }
    }

    /** @return array<string, list<string>|null> */
    private function permissionsByRole(): array
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
