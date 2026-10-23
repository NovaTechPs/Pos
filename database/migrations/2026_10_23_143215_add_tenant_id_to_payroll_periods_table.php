<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_periods', 'tenant_id')) {
            Schema::table('payroll_periods', function (Blueprint $table): void {
                $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        DB::table('payroll_periods')->whereNull('tenant_id')->orderBy('id')->chunkById(200, function ($periods): void {
            foreach ($periods as $period) {
                $tenantIds = DB::table('employee_payrolls')->join('employees', 'employees.id', '=', 'employee_payrolls.employee_id')
                    ->where('payroll_period_id', $period->id)->whereNotNull('employees.tenant_id')->distinct()->pluck('employees.tenant_id');
                if ($tenantIds->count() > 1) {
                    throw new RuntimeException('Payroll period '.$period->id.' contains multiple tenants. Split the legacy period before upgrading.');
                }
                if ($tenantIds->count() === 1) {
                    DB::table('payroll_periods')->where('id', $period->id)->update(['tenant_id' => $tenantIds->first()]);
                }
            }
        });
    }

    /** Tenant ownership must be preserved when rolling back a release. */
    public function down(): void {}
};
