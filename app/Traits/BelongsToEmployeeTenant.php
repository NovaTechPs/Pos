<?php

namespace App\Traits;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToEmployeeTenant
{
    protected static function bootBelongsToEmployeeTenant(): void
    {
        static::addGlobalScope('employee_tenant', function (Builder $query): void {
            $tenantId = auth()->user()?->tenant_id;
            if ($tenantId) {
                $query->whereHas('employee', fn (Builder $employee) => $employee->where('employees.tenant_id', $tenantId));
            }
        });
        static::saving(function (Model $model): void {
            $tenantId = auth()->user()?->tenant_id;
            $employee = Employee::withoutGlobalScopes()->find($model->getAttribute('employee_id'));
            abort_unless($employee, 404);
            if ($tenantId) {
                abort_unless((int) $employee->tenant_id === (int) $tenantId, 404);
            }

            foreach (['payroll_period_id', 'target_period_id'] as $column) {
                if ($model->getAttribute($column)) {
                    abort_unless($model->getConnection()->table('payroll_periods')
                        ->where('id', $model->getAttribute($column))->where('tenant_id', $employee->tenant_id)->exists(), 404);
                }
            }

            foreach (['employee_payroll_id' => 'employee_payrolls', 'source_payroll_id' => 'employee_payrolls', 'employee_advance_id' => 'employee_advances'] as $column => $table) {
                if ($model->getAttribute($column)) {
                    abort_unless($model->getConnection()->table($table)
                        ->where('id', $model->getAttribute($column))->where('employee_id', $employee->id)->exists(), 404);
                }
            }
        });
    }
}
