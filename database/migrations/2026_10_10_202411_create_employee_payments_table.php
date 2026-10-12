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
        Schema::create('employee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('employee_payroll_id')->nullable()->constrained('employee_payrolls')->nullOnDelete();
            $table->foreignId('employee_advance_id')->nullable()->constrained('employee_advances')->nullOnDelete();

            $table->enum('payment_type', ['payroll', 'advance']); // دفع راتب أم صرف سلفة
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->string('payment_method')->default('cash'); // cash, bank_transfer, check
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'payment_type', 'payment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_payments');
    }
};