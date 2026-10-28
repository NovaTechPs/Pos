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
        SchemaIntegrity::create('employee_payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            $table->decimal('basic_salary', 10, 2)->default(0);
            $table->decimal('total_allowances', 10, 2)->default(0); // إجمالي المكافآت والبدلات
            $table->decimal('total_deductions', 10, 2)->default(0); // إجمالي الخصومات والتأخير
            $table->decimal('advance_deduction', 10, 2)->default(0); // قسط السلفة المقصوص
            $table->decimal('net_salary', 10, 2); // الصافي المستحق
            $table->decimal('paid_amount', 10, 2)->default(0); // ما تم دفعه فعلية

            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->json('earnings_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id']);
            $table->index('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_payrolls');
    }
};
