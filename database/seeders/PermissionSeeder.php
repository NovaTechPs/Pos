<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [

            /*
            |--------------------------------------------------------------------------
            | Dashboard
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'dashboard.view',
                'display_name' => 'عرض لوحة التحكم',
                'group' => 'لوحة التحكم',
            ],

            /*
            |--------------------------------------------------------------------------
            | Branches
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'branches.view',
                'display_name' => 'عرض الفروع',
                'group' => 'الفروع',
            ],
            [
                'name' => 'branches.create',
                'display_name' => 'إضافة فرع',
                'group' => 'الفروع',
            ],
            [
                'name' => 'branches.edit',
                'display_name' => 'تعديل الفرع',
                'group' => 'الفروع',
            ],
            [
                'name' => 'branches.delete',
                'display_name' => 'حذف الفرع',
                'group' => 'الفروع',
            ],

            /*
            |--------------------------------------------------------------------------
            | Roles & Permissions
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'roles.view',
                'display_name' => 'عرض الأدوار',
                'group' => 'الصلاحيات',
            ],
            [
                'name' => 'roles.create',
                'display_name' => 'إضافة دور',
                'group' => 'الصلاحيات',
            ],
            [
                'name' => 'roles.edit',
                'display_name' => 'تعديل الدور',
                'group' => 'الصلاحيات',
            ],
            [
                'name' => 'roles.delete',
                'display_name' => 'حذف الدور',
                'group' => 'الصلاحيات',
            ],
            [
                'name' => 'roles.permissions',
                'display_name' => 'إدارة صلاحيات الدور',
                'group' => 'الصلاحيات',
            ],

            /*
            |--------------------------------------------------------------------------
            | Employees
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'employees.view',
                'display_name' => 'عرض الموظفين',
                'group' => 'الموظفون',
            ],
            [
                'name' => 'employees.create',
                'display_name' => 'إضافة موظف',
                'group' => 'الموظفون',
            ],
            [
                'name' => 'employees.edit',
                'display_name' => 'تعديل الموظف',
                'group' => 'الموظفون',
            ],
            [
                'name' => 'employees.delete',
                'display_name' => 'حذف الموظف',
                'group' => 'الموظفون',
            ],

            /*
            |--------------------------------------------------------------------------
            | Products
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'products.view',
                'display_name' => 'عرض المنتجات',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.create',
                'display_name' => 'إضافة منتج',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.edit',
                'display_name' => 'تعديل المنتج',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.delete',
                'display_name' => 'حذف المنتج',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.price.view',
                'display_name' => 'عرض أسعار المنتجات',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.price.edit',
                'display_name' => 'تعديل أسعار المنتجات',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.cost.view',
                'display_name' => 'عرض تكلفة المنتجات',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.cost.edit',
                'display_name' => 'تعديل تكلفة المنتجات',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.barcode.manage',
                'display_name' => 'إدارة باركود المنتجات',
                'group' => 'المنتجات',
            ],
            [
                'name' => 'products.stock.adjust',
                'display_name' => 'تعديل المخزون',
                'group' => 'المنتجات',
            ],

            /*
            |--------------------------------------------------------------------------
            | Categories
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'categories.view',
                'display_name' => 'عرض التصنيفات',
                'group' => 'التصنيفات',
            ],
            [
                'name' => 'categories.create',
                'display_name' => 'إضافة تصنيف',
                'group' => 'التصنيفات',
            ],
            [
                'name' => 'categories.edit',
                'display_name' => 'تعديل التصنيف',
                'group' => 'التصنيفات',
            ],
            [
                'name' => 'categories.delete',
                'display_name' => 'حذف التصنيف',
                'group' => 'التصنيفات',
            ],

            /*
            |--------------------------------------------------------------------------
            | Customers
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'customers.view',
                'display_name' => 'عرض العملاء',
                'group' => 'العملاء',
            ],
            [
                'name' => 'customers.create',
                'display_name' => 'إضافة عميل',
                'group' => 'العملاء',
            ],
            [
                'name' => 'customers.edit',
                'display_name' => 'تعديل العميل',
                'group' => 'العملاء',
            ],
            [
                'name' => 'customers.delete',
                'display_name' => 'حذف العميل',
                'group' => 'العملاء',
            ],
            [
                'name' => 'customers.statement',
                'display_name' => 'عرض كشف حساب العميل',
                'group' => 'العملاء',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sales
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'sales.view',
                'display_name' => 'عرض المبيعات',
                'group' => 'المبيعات',
            ],
            [
                'name' => 'sales.create',
                'display_name' => 'إنشاء عملية بيع',
                'group' => 'المبيعات',
            ],
            [
                'name' => 'sales.edit',
                'display_name' => 'تعديل عملية بيع',
                'group' => 'المبيعات',
            ],
            [
                'name' => 'sales.delete',
                'display_name' => 'حذف عملية بيع',
                'group' => 'المبيعات',
            ],
            [
                'name' => 'sales.print',
                'display_name' => 'طباعة فاتورة البيع',
                'group' => 'المبيعات',
            ],
            [
                'name' => 'sales.discount',
                'display_name' => 'تطبيق خصم على البيع',
                'group' => 'المبيعات',
            ],
            [
                'name' => 'sales.return',
                'display_name' => 'إرجاع عملية بيع',
                'group' => 'المبيعات',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sales Invoices
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'sales_invoices.view',
                'display_name' => 'عرض فواتير المبيعات',
                'group' => 'فواتير المبيعات',
            ],
            [
                'name' => 'sales_invoices.create',
                'display_name' => 'إنشاء فاتورة مبيعات',
                'group' => 'فواتير المبيعات',
            ],
            [
                'name' => 'sales_invoices.edit',
                'display_name' => 'تعديل فاتورة مبيعات',
                'group' => 'فواتير المبيعات',
            ],
            [
                'name' => 'sales_invoices.delete',
                'display_name' => 'حذف فاتورة مبيعات',
                'group' => 'فواتير المبيعات',
            ],
            [
                'name' => 'sales_invoices.print',
                'display_name' => 'طباعة فاتورة مبيعات',
                'group' => 'فواتير المبيعات',
            ],

            /*
            |--------------------------------------------------------------------------
            | Purchases
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'purchases.view',
                'display_name' => 'عرض المشتريات',
                'group' => 'المشتريات',
            ],
            [
                'name' => 'purchases.create',
                'display_name' => 'إنشاء عملية شراء',
                'group' => 'المشتريات',
            ],
            [
                'name' => 'purchases.edit',
                'display_name' => 'تعديل عملية شراء',
                'group' => 'المشتريات',
            ],
            [
                'name' => 'purchases.delete',
                'display_name' => 'حذف عملية شراء',
                'group' => 'المشتريات',
            ],
            [
                'name' => 'purchases.print',
                'display_name' => 'طباعة فاتورة شراء',
                'group' => 'المشتريات',
            ],
            [
                'name' => 'suppliers.view',
                'display_name' => 'عرض الموردين',
                'group' => 'المشتريات',
            ],
            [
                'name' => 'suppliers.create',
                'display_name' => 'إضافة مورد',
                'group' => 'المشتريات',
            ],
            [
                'name' => 'suppliers.edit',
                'display_name' => 'تعديل المورد',
                'group' => 'المشتريات',
            ],
            [
                'name' => 'suppliers.delete',
                'display_name' => 'حذف المورد',
                'group' => 'المشتريات',
            ],

            /*
            |--------------------------------------------------------------------------
            | POS
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'pos.view',
                'display_name' => 'دخول نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.product.search',
                'display_name' => 'البحث عن المنتجات في نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.product.add',
                'display_name' => 'إضافة منتج إلى الفاتورة',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.product.remove',
                'display_name' => 'حذف منتج من الفاتورة',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.product.quantity',
                'display_name' => 'تعديل كمية المنتج',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.price.view',
                'display_name' => 'عرض سعر المنتج في نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.price.edit',
                'display_name' => 'تعديل سعر البيع في نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.cost.view',
                'display_name' => 'عرض تكلفة المنتج في نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.discount.use',
                'display_name' => 'استخدام الخصم',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.discount.override',
                'display_name' => 'تجاوز حدود الخصم',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.sell_below_cost',
                'display_name' => 'البيع بأقل من التكلفة',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.sale.create',
                'display_name' => 'إنشاء عملية بيع من نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.sale.checkout',
                'display_name' => 'إتمام البيع من نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.sale.print',
                'display_name' => 'طباعة فاتورة نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.return.create',
                'display_name' => 'إرجاع عملية بيع من نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.invoice.hold',
                'display_name' => 'تعليق الفاتورة',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.invoice.restore',
                'display_name' => 'استعادة الفاتورة المعلقة',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.invoice.search',
                'display_name' => 'البحث في فواتير نقطة البيع',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.shift.open',
                'display_name' => 'فتح الوردية',
                'group' => 'نقطة البيع',
            ],
            [
                'name' => 'pos.shift.close',
                'display_name' => 'إغلاق الوردية',
                'group' => 'نقطة البيع',
            ],

            /*
            |--------------------------------------------------------------------------
            | Wholesale Sales
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'wholesale_sales.view',
                'display_name' => 'عرض مبيعات الجملة',
                'group' => 'مبيعات الجملة',
            ],
            [
                'name' => 'wholesale_sales.create',
                'display_name' => 'إنشاء مبيعات جملة',
                'group' => 'مبيعات الجملة',
            ],
            [
                'name' => 'wholesale_sales.edit',
                'display_name' => 'تعديل مبيعات الجملة',
                'group' => 'مبيعات الجملة',
            ],
            [
                'name' => 'wholesale_sales.delete',
                'display_name' => 'حذف مبيعات الجملة',
                'group' => 'مبيعات الجملة',
            ],
            [
                'name' => 'wholesale_sales.print',
                'display_name' => 'طباعة مبيعات الجملة',
                'group' => 'مبيعات الجملة',
            ],

            /*
            |--------------------------------------------------------------------------
            | Van Sales
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'van_sales.view',
                'display_name' => 'عرض مبيعات الفان',
                'group' => 'مبيعات الفان',
            ],
            [
                'name' => 'van_sales.create',
                'display_name' => 'إنشاء مبيعات الفان',
                'group' => 'مبيعات الفان',
            ],
            [
                'name' => 'van_sales.edit',
                'display_name' => 'تعديل مبيعات الفان',
                'group' => 'مبيعات الفان',
            ],
            [
                'name' => 'van_sales.delete',
                'display_name' => 'حذف مبيعات الفان',
                'group' => 'مبيعات الفان',
            ],
            [
                'name' => 'van_sales.print',
                'display_name' => 'طباعة مبيعات الفان',
                'group' => 'مبيعات الفان',
            ],

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'reports.sales',
                'display_name' => 'عرض تقارير المبيعات',
                'group' => 'التقارير',
            ],
            [
                'name' => 'reports.purchases',
                'display_name' => 'عرض تقارير المشتريات',
                'group' => 'التقارير',
            ],
            [
                'name' => 'reports.inventory',
                'display_name' => 'عرض تقارير المخزون',
                'group' => 'التقارير',
            ],
            [
                'name' => 'reports.profit',
                'display_name' => 'عرض تقارير الأرباح',
                'group' => 'التقارير',
            ],
            [
                'name' => 'reports.analytics',
                'display_name' => 'عرض التحليلات',
                'group' => 'التقارير',
            ],
            [
                'name' => 'reports.daily_settlement',
                'display_name' => 'عرض التسوية اليومية',
                'group' => 'التقارير',
            ],

            /*
            |--------------------------------------------------------------------------
            | Store
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'store.view',
                'display_name' => 'عرض المتجر',
                'group' => 'المتجر',
            ],
            [
                'name' => 'store.edit',
                'display_name' => 'تعديل إعدادات المتجر',
                'group' => 'المتجر',
            ],
            [
                'name' => 'store.orders.view',
                'display_name' => 'عرض طلبات المتجر',
                'group' => 'المتجر',
            ],
            [
                'name' => 'store.orders.update',
                'display_name' => 'تحديث طلبات المتجر',
                'group' => 'المتجر',
            ],

            /*
            |--------------------------------------------------------------------------
            | Accounting
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'accounting.voucher.view',
                'display_name' => 'عرض سندات الصرف',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.voucher.create',
                'display_name' => 'إضافة سند صرف',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.voucher.edit',
                'display_name' => 'تعديل سند صرف',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.voucher.delete',
                'display_name' => 'حذف سند صرف',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.receipt.view',
                'display_name' => 'عرض سندات القبض',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.receipt.create',
                'display_name' => 'إضافة سند قبض',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.receipt.edit',
                'display_name' => 'تعديل سند قبض',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.receipt.delete',
                'display_name' => 'حذف سند قبض',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.payments.view',
                'display_name' => 'عرض الدفعات',
                'group' => 'المحاسبة',
            ],
            [
                'name' => 'accounting.payments.create',
                'display_name' => 'إضافة دفعة',
                'group' => 'المحاسبة',
            ],

            /*
            |--------------------------------------------------------------------------
            | Backup
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'backup.view',
                'display_name' => 'عرض النسخ الاحتياطي',
                'group' => 'النظام',
            ],
            [
                'name' => 'backup.create',
                'display_name' => 'إنشاء نسخة احتياطية',
                'group' => 'النظام',
            ],
            [
                'name' => 'backup.restore',
                'display_name' => 'استعادة نسخة احتياطية',
                'group' => 'النظام',
            ],

            /*
            |--------------------------------------------------------------------------
            | System / Users
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'users.view',
                'display_name' => 'عرض المستخدمين',
                'group' => 'الإدارة',
            ],
            [
                'name' => 'users.create',
                'display_name' => 'إضافة مستخدم',
                'group' => 'الإدارة',
            ],
            [
                'name' => 'users.edit',
                'display_name' => 'تعديل المستخدم',
                'group' => 'الإدارة',
            ],
            [
                'name' => 'users.delete',
                'display_name' => 'حذف المستخدم',
                'group' => 'الإدارة',
            ],

        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                [
                    'display_name' => $permission['display_name'],
                    'group' => $permission['group'],
                ]
            );
        }
    }
}

