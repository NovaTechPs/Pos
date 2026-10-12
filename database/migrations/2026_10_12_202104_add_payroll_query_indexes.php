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
        Schema::table('employee_bonuses', function (Blueprint $table) {
            $table->index(['employee_id', 'date'], 'employee_bonuses_employee_date_index');
        });

        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->index(['employee_id', 'date'], 'employee_deductions_employee_date_index');
        });

        Schema::table('employee_leaves', function (Blueprint $table) {
            $table->index(['employee_id', 'status', 'start_date'], 'employee_leaves_employee_status_start_index');
        });

        Schema::table('employee_advances', function (Blueprint $table) {
            $table->index(['employee_id', 'status', 'start_installment_date'], 'employee_advances_employee_status_start_index');
        });

        Schema::table('employee_payments', function (Blueprint $table) {
            $table->index(['employee_id', 'payment_date'], 'employee_payments_employee_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_bonuses', function (Blueprint $table) {
            $table->dropIndex('employee_bonuses_employee_date_index');
        });

        Schema::table('employee_deductions', function (Blueprint $table) {
            $table->dropIndex('employee_deductions_employee_date_index');
        });

        Schema::table('employee_leaves', function (Blueprint $table) {
            $table->dropIndex('employee_leaves_employee_status_start_index');
        });

        Schema::table('employee_advances', function (Blueprint $table) {
            $table->dropIndex('employee_advances_employee_status_start_index');
        });

        Schema::table('employee_payments', function (Blueprint $table) {
            $table->dropIndex('employee_payments_employee_date_index');
        });
    }
};
