<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branches (beyond their own) whose selling prices an employee may edit.
     */
    public function up(): void
    {
        Schema::create('user_price_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();
            $table->foreign(['tenant_id', 'branch_id'], 'user_price_branches_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->unique(['user_id', 'branch_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        // Existing databases are not re-seeded on deploy, so the permission is added here too.
        // A fresh database gets it from PermissionSeeder with the rest.
        if (! DB::table('permissions')->exists()) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'products.branch_prices.edit'],
            [
                'display_name' => 'تعديل أسعار البيع للفروع (فرع الموظف أو الفروع المحددة له)',
                'group' => 'المنتجات',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_price_branches');
        DB::table('permissions')->where('name', 'products.branch_prices.edit')->delete();
    }
};
