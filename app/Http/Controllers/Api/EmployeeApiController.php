<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeDeparture;
use App\Models\EmployeeLeave;
use App\Models\EmployeePayment;
use App\Models\EmployeePayroll;
use App\Services\EmployeeAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeApiController extends Controller
{
    public function requestDeparture(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        abort_unless($employee !== null, 404);
        $data = $request->validate(['date' => 'required|date|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i', 'ends_next_day' => 'sometimes|boolean', 'reason' => 'required|string|max:1000']);
        $departure = EmployeeDeparture::create($data + ['employee_id' => $employee->id, 'status' => 'pending', 'is_deductible' => true]);

        return response()->json(['message' => 'تم إرسال طلب المغادرة للمراجعة.', 'departure' => $departure], 201);
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $employee = $this->currentEmployee($request);

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $todayAttendance = EmployeeAttendance::with('sessions')->where('employee_id', $employee->id)
            ->whereDate('date', today())
            ->first();

        return response()->json([
            'user' => $user,
            'employee' => $employee,
            'today_attendance' => $todayAttendance,
        ]);
    }

    public function clock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:check_in,check_out'],
        ]);

        $employee = $this->currentEmployee($request);
        abort_unless($employee && (! isset($validated['employee_id']) || (int) $validated['employee_id'] === $employee->id), 404);

        $attendance = app(EmployeeAttendanceService::class)->clock($employee, $validated['type'], 'self');
        $message = $validated['type'] === 'check_in' ? 'تم تسجيل الحضور بنجاح.' : 'تم تسجيل الانصراف بنجاح.';

        return response()->json([
            'message' => $message,
            'attendance' => $attendance,
        ]);
    }

    public function scanAttendance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_code' => ['required', 'string', 'max:191'],
        ]);

        $employee = Employee::query()
            ->where(function ($query) use ($request): void {
                $query->where('tenant_id', $request->user()->tenant_id)
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('tenant_id', $request->user()->tenant_id));
            })
            ->where('employee_code', $validated['employee_code'])
            ->when($request->user()->branch_id && ! $request->user()->isTenantOwner(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->where('is_active', true)
            ->first();

        if (! $employee) {
            return response()->json(['message' => 'باركود الموظف غير صحيح أو أن الموظف غير نشط.'], 404);
        }

        $type = $employee->attendanceSessions()->whereNull('checked_out_at')->exists() ? 'check_out' : 'check_in';
        $attendance = app(EmployeeAttendanceService::class)->clock($employee, $type, 'barcode');
        $message = $type === 'check_in' ? 'تم تسجيل الحضور بنجاح.' : 'تم تسجيل الانصراف بنجاح.';

        return response()->json([
            'message' => $message,
            'type' => $type,
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
            ],
            'attendance' => $attendance,
        ]);
    }

    public function finances(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        return response()->json([
            'payroll' => EmployeePayroll::with('payrollPeriod')->where('employee_id', $employee->id)->latest()->limit(12)->get(),
            'advances' => EmployeeAdvance::where('employee_id', $employee->id)->latest()->limit(20)->get(),
            'leaves' => EmployeeLeave::where('employee_id', $employee->id)->latest()->limit(20)->get(),
        ]);
    }

    public function requestAdvance(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'installment_amount' => ['required', 'numeric', 'min:1'], 'start_installment_date' => ['required', 'date', 'after_or_equal:today'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $advance = EmployeeAdvance::create($data + ['employee_id' => $employee->id, 'status' => 'pending', 'paid_amount' => 0]);

        return response()->json(['message' => 'تم إرسال طلب السلفة', 'advance' => $advance], 201);
    }

    public function requestLeave(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);
        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }
        $data = $request->validate(['type' => ['required', 'in:annual,sick,unpaid,emergency'], 'start_date' => ['required', 'date', 'after_or_equal:today'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $days = (int) (Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1);
        $leave = EmployeeLeave::create($data + ['employee_id' => $employee->id, 'status' => 'pending', 'total_days' => $days]);

        return response()->json(['message' => 'تم إرسال طلب الإجازة', 'leave' => $leave], 201);
    }

    public function attendanceIndex(Request $request): JsonResponse
    {
        $attendance = EmployeeAttendance::query()->with('employee')
            ->whereHas('employee', function ($query) use ($request): void {
                $query->where('tenant_id', $request->user()->tenant_id)
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('tenant_id', $request->user()->tenant_id));
            })
            ->when($request->user()->branch_id && ! $request->user()->isTenantOwner(), fn ($query) => $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('branch_id', $request->user()->branch_id)))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('date', $request->date('date')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('date', '<=', $request->date('to')))
            ->latest('date')->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json(['attendance' => $attendance]);
    }

    public function index(Request $request): JsonResponse
    {
        $employees = Employee::query()->where(function ($query) use ($request): void {
            $query->where('tenant_id', $request->user()->tenant_id)
                ->orWhereHas('user', fn ($userQuery) => $userQuery->where('tenant_id', $request->user()->tenant_id));
        })->when($request->user()->branch_id && ! $request->user()->isTenantOwner(), fn ($query) => $query->where('branch_id', $request->user()->branch_id))
            ->latest()->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json(['employees' => $employees]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:100', Rule::unique('employees', 'employee_code')->where('tenant_id', $request->user()->tenant_id)],
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'], 'employment_type' => ['required', 'in:full_time,part_time,contract,daily'],
            'salary_type' => ['required', 'in:monthly,hourly,daily,production'], 'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'], 'hire_date' => ['required', 'date'], 'branch_id' => ['nullable', 'integer'],
        ]);
        $employee = Employee::create($data + ['tenant_id' => $request->user()->tenant_id, 'branch_id' => $request->user()->branch_id, 'is_active' => true]);

        return response()->json(['employee' => $employee], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->assertEmployeeTenant($request, $employee);
        $data = $request->validate([
            'employee_code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('employees', 'employee_code')->where('tenant_id', $request->user()->tenant_id)->ignore($employee->id)],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'], 'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'], 'employment_type' => ['sometimes', 'in:full_time,part_time,contract,daily'],
            'salary_type' => ['sometimes', 'in:monthly,hourly,daily,production'], 'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'], 'hire_date' => ['sometimes', 'date'], 'is_active' => ['sometimes', 'boolean'], 'branch_id' => ['nullable', 'integer'],
        ]);
        $employee->update($data);

        return response()->json(['employee' => $employee->fresh()]);
    }

    public function advanceRequests(Request $request): JsonResponse
    {
        $advances = EmployeeAdvance::query()->with('employee')->whereHas('employee', function ($query) use ($request): void {
            $query->where('tenant_id', $request->user()->tenant_id)
                ->orWhereHas('user', fn ($userQuery) => $userQuery->where('tenant_id', $request->user()->tenant_id));
        })->when($request->user()->branch_id && ! $request->user()->isTenantOwner(), fn ($query) => $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('branch_id', $request->user()->branch_id)))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json(['advances' => $advances]);
    }

    public function updateAdvance(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $employee = $advance->employee;
        abort_unless($employee instanceof Employee, 404);
        $this->assertEmployeeTenant($request, $employee);
        $data = $request->validate(['status' => ['required', 'in:active,rejected'], 'notes' => ['nullable', 'string', 'max:1000']]);
        abort_unless($advance->status === 'pending', 409, 'يمكن معالجة طلب السلفة المعلّق فقط.');
        $advance->update($data);

        return response()->json(['advance' => $advance->fresh('employee')]);
    }

    public function payAdvance(Request $request, EmployeeAdvance $advance): JsonResponse
    {
        $employee = $advance->employee;
        abort_unless($employee instanceof Employee, 404);
        $this->assertEmployeeTenant($request, $employee);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'payment_date' => ['nullable', 'date'], 'payment_method' => ['required', 'in:cash,bank_transfer,cheque'], 'reference_number' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:1000']]);
        abort_unless($advance->status === 'active', 409, 'يجب اعتماد السلفة قبل صرفها.');
        $payment = DB::transaction(function () use ($advance, $data): EmployeePayment {
            $lockedAdvance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $remaining = (float) $lockedAdvance->amount - (float) $lockedAdvance->paid_amount;
            abort_if((float) $data['amount'] > $remaining, 422, 'قيمة الصرف تتجاوز المتبقي من السلفة.');
            $payment = EmployeePayment::create($data + [
                'employee_id' => $lockedAdvance->employee_id, 'employee_advance_id' => $lockedAdvance->id,
                'payment_type' => 'advance', 'payment_date' => $data['payment_date'] ?? today(),
            ]);
            $lockedAdvance->paid_amount = (float) $lockedAdvance->paid_amount + (float) $data['amount'];
            if ((float) $lockedAdvance->paid_amount >= (float) $lockedAdvance->amount) {
                $lockedAdvance->status = 'completed';
            }
            $lockedAdvance->save();

            return $payment;
        }, 3);

        return response()->json(['message' => 'تم صرف السلفة', 'payment' => $payment], 201);
    }

    private function assertEmployeeTenant(Request $request, Employee $employee): void
    {
        abort_unless((int) $employee->tenant_id === (int) $request->user()->tenant_id || (int) $employee->user?->tenant_id === (int) $request->user()->tenant_id, 404);
        abort_unless(! $request->user()->branch_id || $request->user()->isTenantOwner() || (int) $employee->branch_id === (int) $request->user()->branch_id, 404);
    }

    private function currentEmployee(Request $request): ?Employee
    {
        $user = $request->user() ?? auth()->user();

        return $user ? Employee::query()->where('user_id', $user->id)
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->first() : null;
    }
}
