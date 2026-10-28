<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['employee_advance_id' => 'employee_advances', 'employee_payroll_carryover_id' => 'employee_payroll_carryovers'] as $column => $target) {
            if (! Schema::hasColumn('employee_payroll_items', $column)) {
                Schema::table('employee_payroll_items', function (Blueprint $table) use ($column, $target): void {
                    $table->foreignId($column)->nullable()->constrained($target)->nullOnDelete();
                });
            }
        }
    }

    /** Preserve payroll links that are also part of the original creation schema. */
    public function down(): void {}
};
