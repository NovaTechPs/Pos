
<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SchemaIntegrity::create('employee_payroll_carryover_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_payroll_carryover_id');

            $table->foreignId('employee_payroll_id');

            $table->decimal('amount', 12, 2);

            $table->timestamps();

            $table->foreign(
                'employee_payroll_carryover_id',
                'ep_carryover_app_carryover_fk'
            )->references('id')
                ->on('employee_payroll_carryovers')
                ->cascadeOnDelete();

            $table->foreign(
                'employee_payroll_id',
                'ep_carry_app_payroll_fk'
            )->references('id')
                ->on('employee_payrolls')
                ->cascadeOnDelete();

            $table->unique(
                ['employee_payroll_carryover_id', 'employee_payroll_id'],
                'payroll_carryover_application_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_carryover_applications');
    }
};
