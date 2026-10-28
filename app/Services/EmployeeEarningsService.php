<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDeparture;
use App\Models\PayrollPeriod;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EmployeeEarningsService
{
    /** @return array<string, float|int> */
    public function calculate(Employee $employee, PayrollPeriod $period): array
    {
        $start = Carbon::parse($period->start_date)->startOfDay()->max(Carbon::parse($employee->hire_date)->startOfDay());
        $end = Carbon::parse($period->end_date)->startOfDay();
        if ($employee->terminated_at) {
            $end = $end->min(Carbon::parse($employee->terminated_at)->startOfDay());
        }
        $attendances = $employee->attendances()->with('sessions')->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->keyBy('date');
        $leaves = $employee->leaves()->where('status', 'approved')->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->get();
        $departures = $employee->departures()->where('status', 'approved')->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy('date');
        $histories = $employee->salaryHistories()->orderByDesc('effective_from')->get();
        $shifts = $employee->shifts()->orderByDesc('effective_from')->get();
        $basic = $deductions = $overtimePay = 0.0;
        $workedDays = $paidLeaveDays = $absentDays = $openSessions = 0;
        $overtimeMultiplier = max(1, (float) TenantSetting::get((int) $employee->tenant_id, 'overtime_multiplier', 1));
        $lastDailyRate = 0.0;
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $history = $histories->first(fn ($h): bool => Carbon::parse($h->effective_from)->lte($day) && (! $h->effective_until || Carbon::parse($h->effective_until)->gte($day)));
            $salaryType = $history->salary_type ?? $employee->salary_type;
            $salary = (float) ($history->basic_salary ?? $employee->basic_salary);
            $hourRate = (float) ($history->hourly_rate ?? $employee->hourly_rate);
            $shift = $shifts->first(fn ($s): bool => $s->effective_from <= $date && (! $s->effective_until || $s->effective_until >= $date));
            $workingDay = ! $shift || in_array(strtolower($day->format('D')), $shift->work_days, true);
            $attendance = $attendances->get($date);
            $windowStart = Carbon::parse($date.' '.($shift->start_time ?? $attendance->check_in ?? '09:00:00'));
            $windowEnd = $shift ? Carbon::parse($date.' '.$shift->end_time) : $windowStart->copy()->addHours(8);
            if ($windowEnd->lte($windowStart)) {
                $windowEnd->addDay();
            }
            $scheduledMinutes = (int) $windowStart->diffInMinutes($windowEnd, true);
            $dailyRate = $salaryType === 'monthly' ? $salary / $day->daysInMonth : ($salaryType === 'hourly' ? $hourRate * $scheduledMinutes / 60 : $salary);
            $lastDailyRate = $dailyRate;
            $ratePerMinute = $salaryType === 'hourly' ? $hourRate / 60 : $dailyRate / max(1, $scheduledMinutes);
            $leave = $leaves->first(fn ($leave): bool => $leave->start_date <= $date && $leave->end_date >= $date);
            $paidLeave = $workingDay && $leave && $leave->type !== 'unpaid';
            $absent = $workingDay && (($leave && $leave->type === 'unpaid') || ($attendance?->status === 'absent' && ! $paidLeave));
            $paidLeaveDays += $paidLeave ? 1 : 0;
            $absentDays += $absent ? 1 : 0;
            $workedDays += $attendance && in_array($attendance->status, ['present', 'late'], true) ? 1 : 0;
            $workedMinutes = $attendance ? (int) $attendance->worked_minutes : 0;
            $intervals = [];
            if ($attendance) {
                foreach ($attendance->sessions as $session) {
                    if (! $session->checked_out_at) {
                        $openSessions++;

                        continue;
                    }
                    $intervals[] = [$session->checked_in_at->getTimestamp(), $session->checked_out_at->getTimestamp()];
                }
                if ($attendance->sessions->isEmpty() && $attendance->check_in && $attendance->check_out) {
                    $in = Carbon::parse($date.' '.$attendance->check_in);
                    $out = Carbon::parse($date.' '.$attendance->check_out);
                    if ($out->lte($in)) {
                        $out->addDay();
                    }
                    $intervals = [[$in->getTimestamp(), $out->getTimestamp()]];
                    $workedMinutes = (int) $in->diffInMinutes($out, true);
                } elseif ($attendance->sessions->isEmpty() && $attendance->check_in && ! $attendance->check_out) {
                    $openSessions++;
                }
            }
            $rawOvertime = $workingDay ? max(0, $workedMinutes - $scheduledMinutes) : $workedMinutes;
            $approvedOvertime = min($rawOvertime, (int) ($attendance->overtime_approved_minutes ?? 0));
            if ($salaryType === 'monthly') {
                $basic += $dailyRate;
            } elseif ($salaryType === 'hourly') {
                $basic += ($workingDay ? min($workedMinutes, $scheduledMinutes) : 0) * $ratePerMinute;
                if ($paidLeave) {
                    $basic += $dailyRate;
                }
            } elseif ($salaryType === 'daily') {
                if (($workingDay && $workedMinutes > 0) || $paidLeave) {
                    $basic += $dailyRate;
                }
            } elseif ($salaryType === 'production') {
                $basic += (float) ($attendance->production_units ?? 0) * $salary;
            }
            if ($salaryType !== 'production') {
                $overtimePay += $approvedOvertime * $ratePerMinute * $overtimeMultiplier;
            }
            if (in_array($salaryType, ['monthly', 'daily'], true)) {
                if ($absent && $salaryType === 'monthly') {
                    $deductions += $dailyRate;
                } elseif ($workingDay && ! $paidLeave && $attendance && $intervals !== []) {
                    $deductions += $this->unpaidMinutes($windowStart->getTimestamp(), $windowEnd->getTimestamp(), $intervals, $departures->get($date, collect()), $date, (int) ($shift->grace_period_minutes ?? 0)) * $ratePerMinute;
                }
            }
        }

        return ['basic_salary' => round($basic, 2), 'attendance_deductions' => round(min($basic, $deductions), 2),
            'overtime_pay' => round($overtimePay, 2), 'actual_worked_days' => $workedDays, 'paid_leave_days' => $paidLeaveDays,
            'unpaid_absent_days' => $absentDays, 'open_sessions' => $openSessions, 'daily_rate' => round($lastDailyRate, 2)];
    }

    /**
     * @param  array<int, array{int, int}>  $worked
     * @param  Collection<int, EmployeeDeparture>  $departures
     */
    private function unpaidMinutes(int $start, int $end, array $worked, Collection $departures, string $date, int $grace): float
    {
        if ($worked === []) {
            return 0;
        }
        $gaps = [[$start, $end]];
        foreach ($worked as [$in, $out]) {
            $gaps = $this->subtract($gaps, $in, $out);
        }
        $first = min(array_column($worked, 0));
        if ($first > $start && $first <= $start + $grace * 60) {
            $gaps = $this->subtract($gaps, $start, $first);
        }
        foreach ($departures->where('is_deductible', true) as $departure) {
            [$in, $out] = $this->departureInterval($departure, $date);
            if ($out > $start && $in < $end) {
                $gaps[] = [max($start, $in), min($end, $out)];
            }
        }
        usort($gaps, fn ($a, $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($gaps as [$in, $out]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last][1] >= $in) {
                $merged[$last][1] = max($merged[$last][1], $out);
            } else {
                $merged[] = [$in, $out];
            }
        }
        foreach ($departures->where('is_deductible', false) as $departure) {
            [$in, $out] = $this->departureInterval($departure, $date);
            $merged = $this->subtract($merged, $in, $out);
        }

        return array_sum(array_map(fn ($interval): float => ($interval[1] - $interval[0]) / 60, $merged));
    }

    /** @return array{int, int} */
    private function departureInterval(mixed $departure, string $date): array
    {
        $in = Carbon::parse($date.' '.$departure->start_time);
        $out = Carbon::parse($date.' '.$departure->end_time);
        if ($departure->ends_next_day || $out->lte($in)) {
            $out->addDay();
        }

        return [$in->getTimestamp(), $out->getTimestamp()];
    }

    /**
     * @param  array<int, array{int, int}>  $intervals
     * @return array<int, array{int, int}>
     */
    private function subtract(array $intervals, int $start, int $end): array
    {
        $result = [];
        foreach ($intervals as [$in, $out]) {
            if ($end <= $in || $start >= $out) {
                $result[] = [$in, $out];

                continue;
            }
            if ($start > $in) {
                $result[] = [$in, min($start, $out)];
            }
            if ($end < $out) {
                $result[] = [max($end, $in), $out];
            }
        }

        return $result;
    }
}
