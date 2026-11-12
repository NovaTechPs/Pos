<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;

/**
 * Store-wide payroll rules: overtime multipliers and yearly leave entitlements.
 * An employee may have their own leave entitlement; otherwise the store's default applies.
 */
class PayrollSettings
{
    public const DEFAULTS = [
        'overtime_multiplier' => 1.0,
        'rest_day_multiplier' => 1.0,
        'holiday_multiplier' => 1.0,
        'annual_leave_days' => 14.0,
        'sick_leave_days' => 7.0,
    ];

    public function get(int $tenantId, string $key): float
    {
        $value = TenantSetting::get($tenantId, $key, self::DEFAULTS[$key]);

        return is_numeric($value) ? (float) $value : self::DEFAULTS[$key];
    }

    /** @return array<string, float> */
    public function all(int $tenantId): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(fn (float $default, string $key): array => [$key => $this->get($tenantId, $key)])->all();
    }

    /** @param array<string, float|int|string> $values */
    public function save(int $tenantId, array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            TenantSetting::set($tenantId, $key, (string) round((float) $value, 2));
        }
    }

    /** Yearly entitlement for annual or sick leave; other leave types are not limited. */
    public function entitlement(Employee $employee, string $type): ?float
    {
        return match ($type) {
            'annual' => $employee->annual_leave_days !== null ? (float) $employee->annual_leave_days : $this->get((int) $employee->tenant_id, 'annual_leave_days'),
            'sick' => $employee->sick_leave_days !== null ? (float) $employee->sick_leave_days : $this->get((int) $employee->tenant_id, 'sick_leave_days'),
            default => null,
        };
    }

    /** Approved leave days of this type already used in the calendar year of the given date. */
    public function usedDays(Employee $employee, string $type, string $date, ?int $exceptLeaveId = null): float
    {
        $year = Carbon::parse($date)->year;

        return (float) EmployeeLeave::query()->where('employee_id', $employee->id)->where('type', $type)->where('status', 'approved')
            ->when($exceptLeaveId, fn ($query, $id) => $query->whereKeyNot($id))
            ->whereYear('start_date', $year)->sum('total_days');
    }

    /** Days left this year, or null when the type has no yearly limit. */
    public function remainingDays(Employee $employee, string $type, string $date): ?float
    {
        $entitlement = $this->entitlement($employee, $type);

        return $entitlement === null ? null : max(0, round($entitlement - $this->usedDays($employee, $type, $date), 1));
    }
}
