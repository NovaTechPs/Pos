<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. إنشاء حساب مدير النظام (SaaS Admin)
        User::updateOrCreate(
            ['email' => 'admin@example.com'], // يمنع التكرار عند تشغيل الـ Seed أكثر من مرة
            [
                'name' => 'SaaS Admin',
                'password' => Hash::make('password123'), // يمكنك تغيير كلمة المرور
                'type' => 'saas_admin',
                'is_active' => true,
                'tenant_id' => null,
                'branch_id' => null,
            ]
        );

        // 2. (اختياري) إنشاء حساب مستخدم عادي (Tenant User)
        User::updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Test Tenant User',
                'password' => Hash::make('password123'),
                'type' => 'tenant_user',
                'is_active' => true,
                'tenant_id' => null, // يمكنك ربطه بـ id الـ Tenant إذا أردت
                'branch_id' => null, // يمكنك ربطه بـ id الـ Branch إذا أردت
            ]
        );
    }
}
