<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data scope: which records an employee sees and changes, per area (sales, returns, reports…).
     * A job sets the default for the employee's own branch and for the other branches;
     * an employee can override it for any single branch.
     */
    public function up(): void
    {
        Schema::create('role_data_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('area', 40);
            $table->string('own_branch', 10);
            $table->string('other_branches', 10);
            $table->timestamps();
            $table->unique(['role_id', 'area']);
        });

        Schema::create('user_branch_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('area', 40);
            $table->string('level', 10);
            $table->timestamps();
            $table->unique(['user_id', 'branch_id', 'area']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_branch_scopes');
        Schema::dropIfExists('role_data_scopes');
    }
};
