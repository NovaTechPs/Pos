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
        Schema::create('employee_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_payroll_id')->constrained('employee_payrolls')->cascadeOnDelete();
            $table->enum('type', ['addition', 'deduction']); // إضافة أم خصم
            $table->string('title'); // e.g. "مكافأة تميز", "خصم تأخير 45 دقيقة", "قسط سلفة"
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->index('employee_payroll_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_items');
    }
};