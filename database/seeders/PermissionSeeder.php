<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // المبيعات والـ POS
            ['name' => 'orders.view', 'display_name' => 'عرض الفواتير', 'group' => 'المبيعات'],
            ['name' => 'orders.create', 'display_name' => 'إجراء عملية بيع (POS)', 'group' => 'المبيعات'],
            ['name' => 'orders.apply_discount', 'display_name' => 'تطبيق خصم', 'group' => 'المبيعات'],
            ['name' => 'orders.refund', 'display_name' => 'إلغاء أو إرجاع فاتورة', 'group' => 'المبيعات'],

            // المشتريات والموردين
            ['name' => 'purchases.view', 'display_name' => 'عرض فواتير المشتريات', 'group' => 'المشتريات'],
            ['name' => 'purchases.create', 'display_name' => 'إدخال فاتورة شراء', 'group' => 'المشتريات'],
            ['name' => 'suppliers.manage', 'display_name' => 'إدارة الموردين', 'group' => 'المشتريات'],

            // المنتجات والمخزون
            ['name' => 'products.view', 'display_name' => 'عرض قائمة المنتجات', 'group' => 'المخزون'],
            ['name' => 'products.create', 'display_name' => 'إضافة منتج جديد', 'group' => 'المخزون'],
            ['name' => 'products.edit', 'display_name' => 'تعديل بيانات وأسعار المنتجات', 'group' => 'المخزون'],
            ['name' => 'stock.adjust', 'display_name' => 'تسوية وجرد المخزون', 'group' => 'المخزون'],

            // التقارير والموظفين
            ['name' => 'reports.sales', 'display_name' => 'عرض تقارير المبيعات', 'group' => 'التقارير'],
            ['name' => 'reports.profit', 'display_name' => 'عرض تقارير الأرباح', 'group' => 'التقارير'],
            ['name' => 'users.manage', 'display_name' => 'إدارة الموظفين والأدوار', 'group' => 'الإدارة'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['name' => $permission['name']], $permission);
        }
    }
}
