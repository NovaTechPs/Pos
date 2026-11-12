<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR additions: public holidays, a type for deductions (penalties, damage…), per-employee leave
     * entitlements, and one-off jobs paid to casual workers (unloading, cleaning, arranging stock).
     */
    public function up(): void
    {
        Schema::create('payroll_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->boolean('is_paid')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'date']);
        });

        Schema::table('employee_deductions', function (Blueprint $table) {
            // negligence, damage, lateness, other
            $table->string('category', 30)->default('other');
        });

        Schema::table('employees', function (Blueprint $table) {
            // Yearly days; empty means the store's default.
            $table->decimal('annual_leave_days', 5, 1)->nullable();
            $table->decimal('sick_leave_days', 5, 1)->nullable();
        });

        Schema::create('casual_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('worker_name');
            $table->string('worker_phone')->nullable();
            $table->string('title');
            $table->date('job_date');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->default('cash');
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'job_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casual_jobs');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['annual_leave_days', 'sick_leave_days']);
        });
        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->dropColumn('category');
        });
        Schema::dropIfExists('payroll_holidays');
    }
};
