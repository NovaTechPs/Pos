<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaIntegrity::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('employee_code');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->string('national_id')->nullable();

            // بيانات العمل والراتب
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'daily'])->default('full_time');
            $table->enum('salary_type', ['monthly', 'hourly', 'daily', 'production'])->default('monthly');
            $table->decimal('basic_salary', 10, 2)->default(0);
            $table->decimal('hourly_rate', 8, 2)->default(0);

            $table->date('hire_date');
            $table->boolean('is_active')->default(true);
            $table->date('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->foreignId('terminated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_code']);
            $table->unique(['tenant_id', 'national_id']);
            $table->index(['tenant_id', 'is_active', 'salary_type'], 'employees_tenant_active_salary_index');
        });

        SchemaIntegrity::create('employee_salary_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->enum('salary_type', ['monthly', 'hourly', 'daily', 'production']);
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('hourly_rate', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
            $table->index(['employee_id', 'effective_from', 'effective_until'], 'employee_salary_effective_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_salary_histories');
        Schema::dropIfExists('employees');
    }
};
