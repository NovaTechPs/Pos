<?php

namespace App\Models;

use App\Services\EmployeeAttendanceService;
use App\Traits\BelongsToEmployeeTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EmployeeDeparture extends Model
{
    use BelongsToEmployeeTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $departure): void {
            $employee = Employee::query()->findOrFail($departure->employee_id);
            app(EmployeeAttendanceService::class)->assertOpenPeriod($employee, $departure->date);
            $start = Carbon::parse($departure->date.' '.$departure->start_time);
            $end = Carbon::parse($departure->date.' '.$departure->end_time);
            if ($departure->ends_next_day) {
                $end->addDay();
            }
            if ($end->lte($start) || $start->diffInMinutes($end, true) > 1440) {
                throw ValidationException::withMessages(['departure_end' => 'حدد نهاية صحيحة للمغادرة، وحدد اليوم التالي للمغادرة الليلية.']);
            }
            $departure->minutes = (int) $start->diffInMinutes($end, true);
            $overlap = ! in_array($departure->status, ['rejected', 'cancelled'], true) && self::query()->where('employee_id', $employee->id)
                ->whereBetween('date', [$start->copy()->subDay()->toDateString(), $end->toDateString()])
                ->whereIn('status', ['pending', 'approved'])->when($departure->exists, fn ($q) => $q->whereKeyNot($departure->id))
                ->get()->contains(function (self $other) use ($start, $end): bool {
                    $otherStart = Carbon::parse($other->date.' '.$other->start_time);
                    $otherEnd = Carbon::parse($other->date.' '.$other->end_time);
                    if ($other->ends_next_day) {
                        $otherEnd->addDay();
                    }

                    return $otherStart->lt($end) && $otherEnd->gt($start);
                });
            if ($overlap) {
                throw ValidationException::withMessages(['departure_start' => 'توجد مغادرة متداخلة في الفترة نفسها.']);
            }
            if ($departure->status === 'approved' && ! $departure->approved_at) {
                $approverId = auth()->user()?->id;
                $departure->approved_by = $approverId !== null && $approverId > 0 ? $approverId : null;
                $departure->approved_at = now();
            }
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
