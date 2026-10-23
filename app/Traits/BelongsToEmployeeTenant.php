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
            if ($tenantId) {
                abort_unless(Employee::query()->where('tenant_id', $tenantId)->whereKey($model->getAttribute('employee_id'))->exists(), 404);
            }
        });
    }
}
