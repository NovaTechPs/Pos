<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branches whose selling prices the employees of a role may edit, chosen on the roles screen.
     */
    public function up(): void
    {
        Schema::create('role_price_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();
            $table->foreign(['tenant_id', 'branch_id'], 'role_price_branches_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->unique(['role_id', 'branch_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        // Other branches are now granted per role on the roles screen, so this permission covers the own branch only.
        DB::table('permissions')->where('name', 'products.branch_prices.edit')
            ->update(['display_name' => 'تعديل أسعار البيع لفرع الموظف', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_price_branches');
    }
};
