<?php

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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // ربط بحساب الدخول إن وجد
            $table->string('employee_code')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->string('national_id')->nullable()->unique();

            // بيانات العمل والراتب
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'daily'])->default('full_time');
            $table->enum('salary_type', ['monthly', 'hourly', 'daily', 'production'])->default('monthly');
            $table->decimal('basic_salary', 10, 2)->default(0);
            $table->decimal('hourly_rate', 8, 2)->default(0);

            $table->date('hire_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'salary_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
