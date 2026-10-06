<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Permissions
        |--------------------------------------------------------------------------
        */
        $this->call([
            PermissionSeeder::class,
        ]);


        /*
        |--------------------------------------------------------------------------
        | 2. Plan
        |--------------------------------------------------------------------------
        */

        DB::table('plans')->updateOrInsert(
            ['id' => 1],
            [
                'name'             => 'plan 1',
                'slug'             => 'plan',
                'description'      => null,
                'price'            => 30.00,
                'invoice_period'   => 1,
                'invoice_interval' => 'month',
                'is_active'        => true,
                'created_at'       => '2026-09-15 08:05:51',
                'updated_at'       => '2026-09-15 08:05:51',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | 3. Tenant
        |--------------------------------------------------------------------------
        |
        | مهم:
        | owner_id يجب أن يكون NULL مؤقتًا لأن المستخدم رقم 2
        | لم يتم إنشاؤه بعد.
        |
        */

        DB::table('tenants')->updateOrInsert(
            ['id' => 1],
            [
                'name'       => 'fanous',
                'phone'      => null,
                'plan_id'    => 1,
                'domain'     => 'fanoos.com',
                'is_active'  => true,

                // سيتم تحديثه بعد إنشاء المستخدمين
                'owner_id'   => null,

                'created_at' => '2026-09-15 13:27:17',
                'updated_at' => '2026-09-15 13:27:17',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | 4. Branches
        |--------------------------------------------------------------------------
        */

        $branches = [
            [
                'id'        => 2,
                'tenant_id' => 1,
                'name'      => 'فانوس',
                'phone'     => null,
                'address'   => null,
                'type'      => 'branch',
            ],

            [
                'id'        => 3,
                'tenant_id' => 1,
                'name'      => 'crase',
                'phone'     => null,
                'address'   => null,
                'type'      => 'branch',
            ],

            [
                'id'        => 4,
                'tenant_id' => 1,
                'name'      => 'مخزن جملة',
                'phone'     => null,
                'address'   => null,
                'type'      => 'branch',
            ],
        ];


        foreach ($branches as $branch) {

            DB::table('branches')->updateOrInsert(
                ['id' => $branch['id']],
                array_merge(
                    $branch,
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                )
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 5. Roles
        |--------------------------------------------------------------------------
        */

        $targetTenantId = 1;


        $adminRole = Role::updateOrCreate(
            ['id' => 1],
            [
                'tenant_id'   => $targetTenantId,
                'name'        => 'admin',
                'description' => 'مدير النظام الخاص بالمنشأة',
            ]
        );


        $cashierRole = Role::updateOrCreate(
            ['id' => 2],
            [
                'tenant_id'   => $targetTenantId,
                'name'        => 'cashier',
                'description' => 'كاشير مبيعات',
            ]
        );
        $wholesaleRole = Role::updateOrCreate(
            [
                'tenant_id' => $targetTenantId,
                'name'      => 'wholesale_employee',
            ],
            [
                'description' => 'موظف مبيعات الجملة',
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | 6. Permissions → Roles
        |--------------------------------------------------------------------------
        */

        if (method_exists($adminRole, 'syncPermissions')) {

            $adminRole->syncPermissions(
                Permission::all()
            );

            $cashierRole->syncPermissions([
                'orders.view',
                'orders.create',
                'products.view',
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | 7. Users
        |--------------------------------------------------------------------------
        */

        $defaultPassword = Hash::make(
            'password123'
        );


        $users = [

            /*
            |--------------------------------------------------------------------------
            | SaaS Admin
            |--------------------------------------------------------------------------
            */

            [
                'email'     => 'admin@example.com',
                'name'      => 'SaaS Admin',
                'is_owner'  => false,
                'role_id'   => null,
                'type'      => 'saas_admin',
                'is_active' => true,
                'tenant_id' => null,
                'branch_id' => null,
            ],


            /*
            |--------------------------------------------------------------------------
            | Tenant Owner
            |--------------------------------------------------------------------------
            */

            [
                'email'     => 'user@example.com',
                'name'      => 'Test Tenant User',
                'is_owner'  => true,
                'role_id'   => null,
                'type'      => 'tenant_user',
                'is_active' => true,
                'tenant_id' => 1,
                'branch_id' => 2,
            ],


            /*
            |--------------------------------------------------------------------------
            | Yasmin
            |--------------------------------------------------------------------------
            */

            [
                'email'     => 'yasmin@fanos.com',
                'name'      => 'ياسمين',
                'is_owner'  => false,
                'role_id'   => $adminRole->id,
                'type'      => 'tenant_user',
                'is_active' => true,
                'tenant_id' => 1,
                'branch_id' => 2,
            ],


            /*
            |--------------------------------------------------------------------------
            | Khaled
            |--------------------------------------------------------------------------
            */

            [
                'email'     => 'kalide@fanous.com',
                'name'      => 'خالد',
                'is_owner'  => false,
                'role_id'   => $adminRole->id,
                'type'      => 'tenant_user',
                'is_active' => true,
                'tenant_id' => 1,
                'branch_id' => 3,
            ],


            /*
            |--------------------------------------------------------------------------
            | Hadi
            |--------------------------------------------------------------------------
            */

            [
                'email'     => 'hade@fanous.com',
                'name'      => 'هادي',
                'is_owner'  => false,
                'role_id'   => $wholesaleRole->id,
                'type'      => 'tenant_user',
                'is_active' => true,
                'tenant_id' => 1,
                'branch_id' => 4,
            ],
        ];


        foreach ($users as $userData) {

            $user = User::updateOrCreate(
                [
                    'email' => $userData['email'],
                ],
                array_merge(
                    $userData,
                    [
                        'password' => $defaultPassword,
                    ]
                )
            );


            /*
            |--------------------------------------------------------------------------
            | Assign Roles
            |--------------------------------------------------------------------------
            */

            if (method_exists($user, 'assignRole')) {

                if (
                    $userData['type'] === 'saas_admin'
                    || $userData['is_owner']
                    || $userData['role_id'] == $adminRole->id
                ) {

                    $user->assignRole(
                        $adminRole
                    );
                } elseif (
                    $userData['role_id'] == $cashierRole->id
                ) {

                    $user->assignRole(
                        $cashierRole
                    );
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | 8. Set Tenant Owner
        |--------------------------------------------------------------------------
        |
        | الآن أصبح المستخدم user@example.com موجودًا.
        |
        */

        $tenantOwner = User::where(
            'email',
            'user@example.com'
        )->first();


        if ($tenantOwner) {

            DB::table('tenants')
                ->where('id', 1)
                ->update([
                    'owner_id'   => $tenantOwner->id,
                    'updated_at' => now(),
                ]);
        }
    }
}
