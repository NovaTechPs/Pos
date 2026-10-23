<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('employee_payroll_carryovers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            // allowance: مبلغ مستحق للموظف
            // deduction: مبلغ مطلوب استرداده من الموظف
            $table->enum('type', ['allowance', 'deduction']);

            $table->decimal('amount', 12, 2);
            $table->decimal('applied_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);

            $table->enum('status', [
                'pending',
                'partially_applied',
                'applied',
                'cancelled',
            ])->default('pending');

            $table->string('reason')->nullable();

            $table->foreignId('source_payroll_id')
                ->nullable()
                ->constrained('employee_payrolls')
                ->nullOnDelete();

            $table->foreignId('target_period_id')
                ->nullable()
                ->constrained('payroll_periods')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['target_period_id', 'status']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_carryovers');
    }
};
