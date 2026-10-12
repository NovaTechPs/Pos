    <?php

    use Livewire\Component;
    use Livewire\WithPagination;
    use App\Models\Employee;
    use App\Models\EmployeeAttendance;
    use App\Models\EmployeeAdvance;
    use App\Models\EmployeeDeparture;
    use App\Models\EmployeeLeave;
    use App\Models\EmployeeBonus;
    use App\Models\EmployeeDeduction;
    use App\Models\EmployeePayment;
    use App\Models\EmployeeShift;
    use App\Models\PayrollPeriod;
    use App\Models\EmployeePayroll;
    use App\Models\EmployeePayrollCarryover;
    use App\Models\EmployeePayrollCarryoverApplication;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Validation\ValidationException;
    use App\Services\PayrollCalculatorService;
    use Carbon\Carbon;

    new class extends Component
    {
        use WithPagination;

        // Active Tab State
        public string $activeTab = 'employees';

        // Employee Form Fields
        public bool $showEmployeeModal = false;
        public $employee_code, $first_name, $last_name, $phone, $hire_date;
        public $basic_salary = 0, $hourly_rate = 0, $salary_type = 'monthly', $employment_type = 'full_time';

        // Attendance Form Fields
        public $attendance_date, $employee_id, $check_in, $check_out, $attendance_status = 'present';

        // Attendance Edit Form Fields
        public bool $showEditAttendanceModal = false;
        public $editing_attendance_id;
        public $edit_check_in, $edit_check_out, $edit_status, $edit_notes;

        // Departures Form Fields
        public bool $showDepartureModal = false;
        public $departure_employee_id, $departure_date, $departure_start, $departure_end, $departure_minutes = 60, $is_deductible = true, $departure_reason;

        // Leaves Form Fields
        public bool $showLeaveModal = false;
        public $leave_employee_id, $leave_type = 'annual', $leave_start_date, $leave_end_date, $leave_reason;

        // Advance Form Fields
        public bool $showAdvanceModal = false;
        public $advance_employee_id, $advance_amount = 0, $advance_months = 1, $installment_amount = 0, $start_installment_date;

        // Ledger & Payment Fields
        public $ledger_employee_id;
        public string $ledger_month;
        public bool $showPaymentModal = false;
        public $payment_employee_id, $payment_amount, $payment_date, $payment_notes, $payment_method = 'cash';
        public $payment_period_id;

        // Payroll Form Fields
        public $selectedPeriodId;
        public $period_title, $period_start_date, $period_end_date;
        public bool $showPeriodModal = false;

        public function mount()
        {
            $this->hire_date = now()->format('Y-m-d');
            $this->attendance_date = now()->format('Y-m-d');
            $this->departure_date = now()->format('Y-m-d');
            $this->leave_start_date = now()->format('Y-m-d');
            $this->leave_end_date = now()->format('Y-m-d');
            $this->start_installment_date = now()->format('Y-m-d');
            $this->payment_date = now()->format('Y-m-d');
            $this->period_start_date = now()->startOfMonth()->format('Y-m-d');
            $this->period_end_date = now()->endOfMonth()->format('Y-m-d');
            $this->ledger_month = now()->format('Y-m');
        }

        public function updatedLedgerMonth(): void
        {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->ledger_month) || $this->ledger_month > now()->format('Y-m')) {
                $this->ledger_month = now()->format('Y-m');
                $this->addError('ledger_month', 'اختر الشهر الحالي أو شهرًا سابقًا.');
            } else {
                $this->resetValidation('ledger_month');
            }
        }

        // --- Dynamic Advance Calculation ---
        public function updatedAdvanceAmount()
        {
            $this->calculateInstallment();
        }

        public function updatedAdvanceMonths()
        {
            $this->calculateInstallment();
        }

        private function calculateInstallment()
        {
            if ((float)$this->advance_amount > 0 && (int)$this->advance_months > 0) {
                $this->installment_amount = round((float)$this->advance_amount / (int)$this->advance_months, 2);
            } else {
                $this->installment_amount = 0;
            }
        }

        // --- Employee Actions ---
        public function saveEmployee()
        {
            $this->validate([
                'employee_code' => 'required|unique:employees,employee_code',
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'salary_type' => 'required',
                'hire_date' => 'required|date',
            ]);

            Employee::create([
                'employee_code' => $this->employee_code,
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'phone' => $this->phone,
                'salary_type' => $this->salary_type,
                'employment_type' => $this->employment_type,
                'basic_salary' => $this->salary_type === 'hourly' ? 0 : $this->basic_salary,
                'hourly_rate' => $this->salary_type === 'hourly' ? $this->hourly_rate : 0,
                'hire_date' => $this->hire_date,
                'is_active' => true,
            ]);

            $this->reset(['employee_code', 'first_name', 'last_name', 'phone', 'basic_salary', 'hourly_rate']);
            $this->showEmployeeModal = false;
        }

        // --- Attendance Actions ---
        public function recordAttendance()
        {
            $this->validate([
                'employee_id' => 'required|exists:employees,id',
                'attendance_date' => 'required|date',
                'attendance_status' => 'required',
                'check_out' => 'nullable|after:check_in',
            ], [
                'check_out.after' => 'يجب أن يكون وقت الانصراف بعد وقت الدخول.',
            ]);

            $attendance = EmployeeAttendance::firstOrNew([
                'employee_id' => $this->employee_id,
                'date' => $this->attendance_date,
            ]);

            $this->saveAttendanceData($attendance, $this->attendance_status, $this->check_in, $this->check_out);
            $this->reset(['employee_id', 'check_in', 'check_out']);
        }

        public function editAttendance($id)
        {
            $attendance = EmployeeAttendance::findOrFail($id);
            $this->editing_attendance_id = $attendance->id;
            $this->edit_check_in = $attendance->check_in;
            $this->edit_check_out = $attendance->check_out;
            $this->edit_status = $attendance->status;
            $this->edit_notes = $attendance->notes;
            $this->showEditAttendanceModal = true;
        }

        public function updateAttendance()
        {
            $this->validate([
                'edit_check_out' => 'nullable|after:edit_check_in',
            ], [
                'edit_check_out.after' => 'يجب أن يكون وقت الانصراف بعد وقت الدخول.',
            ]);

            $attendance = EmployeeAttendance::findOrFail($this->editing_attendance_id);
            $attendance->notes = $this->edit_notes;

            $this->saveAttendanceData($attendance, $this->edit_status, $this->edit_check_in, $this->edit_check_out);
            $this->showEditAttendanceModal = false;
        }

        private function saveAttendanceData($attendance, $status, $checkIn, $checkOut)
        {
            $attendance->status = $status;

            if (!empty($checkIn)) {
                $attendance->check_in = $checkIn;

                $shift = EmployeeShift::where('employee_id', $attendance->employee_id)->first();
                if ($shift && $shift->start_time) {
                    $shiftStart = Carbon::parse($attendance->date . ' ' . $shift->start_time);
                    $actualIn = Carbon::parse($attendance->date . ' ' . $checkIn);
                    $graceStart = $shiftStart->copy()->addMinutes($shift->grace_period_minutes ?? 0);

                    if ($actualIn->gt($graceStart)) {
                        $attendance->late_minutes = $actualIn->diffInMinutes($shiftStart);
                        if ($attendance->status === 'present') {
                            $attendance->status = 'late';
                        }
                    } else {
                        $attendance->late_minutes = 0;
                    }
                }
            }

            if (!empty($checkOut)) {
                $attendance->check_out = $checkOut;
            }

            $attendance->save();
        }

        // --- Departures Actions ---
        public function saveDeparture()
        {
            $this->validate([
                'departure_employee_id' => 'required|exists:employees,id',
                'departure_date' => 'required|date',
                'departure_start' => 'required',
                'departure_end' => 'required|after:departure_start',
                'departure_minutes' => 'required|numeric|gt:0',
            ], [
                'departure_end.after' => 'يجب أن يكون وقت نهاية المغادرة بعد وقت البداية.',
            ]);

            EmployeeDeparture::create([
                'employee_id' => $this->departure_employee_id,
                'date' => $this->departure_date,
                'start_time' => $this->departure_start,
                'end_time' => $this->departure_end,
                'minutes' => $this->departure_minutes,
                'is_deductible' => $this->is_deductible,
                'status' => 'approved',
                'reason' => $this->departure_reason,
            ]);

            $this->reset(['departure_employee_id', 'departure_start', 'departure_end', 'departure_reason']);
            $this->showDepartureModal = false;
        }

        // --- Leaves Actions (التحقق من عدم وجود حضور يومي) ---
        public function saveLeave()
        {
            $this->validate([
                'leave_employee_id' => 'required|exists:employees,id',
                'leave_start_date' => 'required|date',
                'leave_end_date' => 'required|date|after_or_equal:leave_start_date',
                'leave_type' => 'required',
            ]);

            $hasAttendance = EmployeeAttendance::where('employee_id', $this->leave_employee_id)
                ->whereBetween('date', [$this->leave_start_date, $this->leave_end_date])
                ->whereNotNull('check_in')
                ->exists();

            if ($hasAttendance) {
                $this->addError('leave_start_date', 'الموظف لديه تسجيل حضور في هذا اليوم! لا يمكن إدخال إجازة يوم كامل، يرجى تسجيلها كمغادرة ساعية من تبويب المغادرات.');
                return;
            }

            $days = Carbon::parse($this->leave_start_date)->diffInDays(Carbon::parse($this->leave_end_date)) + 1;

            EmployeeLeave::create([
                'employee_id' => $this->leave_employee_id,
                'type' => $this->leave_type,
                'start_date' => $this->leave_start_date,
                'end_date' => $this->leave_end_date,
                'total_days' => $days,
                'status' => 'approved',
                'reason' => $this->leave_reason,
            ]);

            $this->reset(['leave_employee_id', 'leave_reason']);
            $this->showLeaveModal = false;
        }

        // --- Advance Actions ---
        public function saveAdvance()
        {
            $this->validate([
                'advance_employee_id' => 'required|exists:employees,id',
                'advance_amount' => 'required|numeric|gt:0',
                'installment_amount' => 'required|numeric|gt:0',
                'start_installment_date' => 'required|date',
            ]);

            EmployeeAdvance::create([
                'employee_id' => $this->advance_employee_id,
                'amount' => $this->advance_amount,
                'installment_amount' => $this->installment_amount,
                'start_installment_date' => $this->start_installment_date,
                'status' => 'active',
            ]);

            $this->reset(['advance_employee_id', 'advance_amount', 'advance_months', 'installment_amount']);
            $this->showAdvanceModal = false;
        }

        // --- Payments / Ledger Actions ---
        public function savePayment()
        {
            $this->validate([
                'payment_employee_id' => 'required|exists:employees,id',
                'payment_period_id' => 'required|exists:payroll_periods,id',
                'payment_amount' => 'required|numeric|gt:0',
                'payment_date' => 'required|date',
                'payment_method' => 'required|in:cash,bank_transfer,check',
            ]);

            DB::transaction(function () {
                $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($this->payment_period_id);
                if (!in_array($period->status, ['approved', 'paid'], true)) {
                    throw ValidationException::withMessages([
                        'payment_period_id' => 'يجب اعتماد مسير الراتب قبل تسجيل دفعة مرتبطة به.',
                    ]);
                }

                $payroll = EmployeePayroll::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $this->payment_employee_id)
                    ->lockForUpdate()
                    ->first();

                if (!$payroll) {
                    throw ValidationException::withMessages([
                        'payment_employee_id' => 'لا يوجد مسير راتب لهذا الموظف في الفترة المحددة.',
                    ]);
                }

                EmployeePayment::create([
                    'employee_id' => $this->payment_employee_id,
                    'employee_payroll_id' => $payroll->id,
                    'amount' => round((float) $this->payment_amount, 2),
                    'payment_date' => $this->payment_date,
                    'payment_type' => 'payroll',
                    'payment_method' => $this->payment_method,
                    'notes' => $this->payment_notes,
                ]);

                $actuallyPaid = (float) EmployeePayment::query()
                    ->where('employee_payroll_id', $payroll->id)
                    ->where('payment_type', 'payroll')
                    ->sum('amount');

                $payroll->paid_amount = round($actuallyPaid, 2);
                $payroll->payment_status = $actuallyPaid >= (float) $payroll->net_salary
                    ? 'paid'
                    : ($actuallyPaid > 0 ? 'partial' : 'unpaid');
                $payroll->save();

                // Anything paid above the approved net salary becomes a carryover,
                // not a new loan/advance. Recompute the single source-payroll record.
                $overpayment = max(0, round($actuallyPaid - (float) $payroll->net_salary, 2));
                if ($overpayment > 0) {
                    $carryover = EmployeePayrollCarryover::query()->firstOrNew([
                        'source_payroll_id' => $payroll->id,
                    ]);
                    $alreadyApplied = $carryover->exists
                        ? (float) $carryover->applications()->sum('amount')
                        : 0.0;
                    $remainingCarryover = max(0, round($overpayment - $alreadyApplied, 2));

                    $carryover->employee_id = $payroll->employee_id;
                    $carryover->amount = $overpayment;
                    $carryover->remaining_amount = $remainingCarryover;
                    $carryover->note = 'دفعة راتب مقدمة - مكسور من الشهر السابق';
                    $carryover->status = $remainingCarryover > 0 ? 'pending' : 'applied';
                    if ($remainingCarryover > 0) {
                        $carryover->target_period_id = null;
                    }
                    $carryover->save();
                }

                $allPayrollsPaid = !EmployeePayroll::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('payment_status', '!=', 'paid')
                    ->exists();
                if ($allPayrollsPaid) {
                    $period->status = 'paid';
                    $period->save();
                }
            });

            $this->reset(['payment_employee_id', 'payment_amount', 'payment_notes']);
            $this->showPaymentModal = false;
            session()->flash('success', 'تم تسجيل الدفعة وتحديث رصيد مسير الراتب.');
        }

        // --- Payroll Actions ---
        public function createPeriod()
        {
            $this->validate([
                'period_title' => 'required|string',
                'period_start_date' => 'required|date',
                'period_end_date' => 'required|date|after_or_equal:period_start_date',
            ]);

            $period = PayrollPeriod::create([
                'title' => $this->period_title,
                'start_date' => $this->period_start_date,
                'end_date' => $this->period_end_date,
                'status' => 'draft',
            ]);

            $this->selectedPeriodId = $period->id;
            $this->showPeriodModal = false;
            $this->reset(['period_title']);
        }

        public function calculatePayroll(PayrollCalculatorService $calculator)
        {
            if (!$this->selectedPeriodId) return;
            $period = PayrollPeriod::findOrFail($this->selectedPeriodId);
            $calculator->calculateForPeriod($period);
            session()->flash('success', 'تم احتساب مسير الرواتب. راجعه ثم اعتمده لتثبيت الأقساط والترحيلات.');
        }

        public function approvePayroll()
        {
            if (!$this->selectedPeriodId) return;

            DB::transaction(function () {
                $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($this->selectedPeriodId);
                if (in_array($period->status, ['approved', 'paid'], true)) {
                    throw ValidationException::withMessages([
                        'selectedPeriodId' => 'مسير هذه الفترة معتمد بالفعل ولا يمكن اعتماده مرة أخرى.',
                    ]);
                }

                $payrolls = EmployeePayroll::query()
                    ->where('payroll_period_id', $period->id)
                    ->with('items')
                    ->lockForUpdate()
                    ->get();

                if ($payrolls->isEmpty()) {
                    throw ValidationException::withMessages([
                        'selectedPeriodId' => 'احتسب الرواتب أولاً قبل اعتماد المسير.',
                    ]);
                }

                foreach ($payrolls as $payroll) {
                    if ((float) $payroll->paid_amount > 0 || $payroll->payments()->exists()) {
                        throw ValidationException::withMessages([
                            'selectedPeriodId' => 'لا يمكن اعتماد المسير بعد تسجيل دفعات عليه.',
                        ]);
                    }

                    foreach ($payroll->items as $item) {
                        if ($item->employee_advance_id) {
                            $advance = EmployeeAdvance::query()->lockForUpdate()->find($item->employee_advance_id);
                            if (!$advance || $advance->status !== 'active') {
                                throw ValidationException::withMessages([
                                    'selectedPeriodId' => 'تعذر اعتماد قسط سلفة؛ راجع السلف المرتبطة بالمسير.',
                                ]);
                            }
                            $remaining = max(0, round((float) $advance->amount - (float) $advance->paid_amount, 2));
                            if ((float) $item->amount > $remaining + 0.009) {
                                throw ValidationException::withMessages([
                                    'selectedPeriodId' => 'تغير رصيد إحدى السلف بعد احتساب المسير. أعد احتساب الفترة قبل الاعتماد.',
                                ]);
                            }
                            $amount = min((float) $item->amount, $remaining);
                            if ($amount <= 0) continue;
                            $advance->paid_amount = round((float) $advance->paid_amount + $amount, 2);
                            if ($advance->paid_amount >= (float) $advance->amount) {
                                $advance->paid_amount = (float) $advance->amount;
                                $advance->status = 'completed';
                            }
                            $advance->save();
                        }

                        if ($item->employee_payroll_carryover_id) {
                            $carryover = EmployeePayrollCarryover::query()
                                ->lockForUpdate()->find($item->employee_payroll_carryover_id);
                            if (!$carryover || $carryover->status !== 'pending') continue;

                            $alreadyApplied = EmployeePayrollCarryoverApplication::query()
                                ->where('employee_payroll_carryover_id', $carryover->id)
                                ->where('employee_payroll_id', $payroll->id)
                                ->exists();
                            if ($alreadyApplied) continue;

                            $amount = min((float) $item->amount, (float) $carryover->remaining_amount);
                            if ($amount <= 0) continue;

                            EmployeePayrollCarryoverApplication::create([
                                'employee_payroll_carryover_id' => $carryover->id,
                                'employee_payroll_id' => $payroll->id,
                                'amount' => round($amount, 2),
                            ]);
                            $carryover->remaining_amount = round((float) $carryover->remaining_amount - $amount, 2);
                            if ($carryover->remaining_amount <= 0) {
                                $carryover->remaining_amount = 0;
                                $carryover->status = 'applied';
                                $carryover->target_period_id = $period->id;
                            } else {
                                // The remaining portion can be deducted from a later payroll.
                                $carryover->target_period_id = null;
                            }
                            $carryover->save();
                        }
                    }
                }

                $period->status = 'approved';
                $period->save();
            });

            session()->flash('success', 'تم اعتماد مسير الراتب وتثبيت أقساط السلف. ستظهر الدفعات الزائدة في الفترة التالية بالملاحظة المحددة.');
        }

        public function with()
        {
            $attendances = EmployeeAttendance::with('employee')
                ->where('date', $this->attendance_date)
                ->get();

            $departuresMinutesByEmployee = EmployeeDeparture::query()
                ->where('date', $this->attendance_date)
                ->whereIn('employee_id', $attendances->pluck('employee_id'))
                ->where('status', 'approved')
                ->selectRaw('employee_id, SUM(minutes) as total_minutes')
                ->groupBy('employee_id')
                ->pluck('total_minutes', 'employee_id');

            $attendances = $attendances->map(function ($att) use ($departuresMinutesByEmployee) {
                    $workedHours = 0;
                    if ($att->check_in && $att->check_out) {
                        $in = Carbon::parse($att->date . ' ' . $att->check_in);
                        $out = Carbon::parse($att->date . ' ' . $att->check_out);
                        $workedHours = round($in->diffInMinutes($out) / 60, 2);
                    }
                    $att->worked_hours = $workedHours;

                    $departuresMinutes = (int) ($departuresMinutesByEmployee[$att->employee_id] ?? 0);

                    $att->departures_hours = $departuresMinutes > 0 ? round($departuresMinutes / 60, 2) : 0;

                    return $att;
                });

            // احتساب كشف الحساب المالي الموحد الدقيق بناءً على أيام الدوام والإجازات المدفوعة
            $ledgerData = null;
            if ($this->ledger_employee_id) {
                $employee = Employee::find($this->ledger_employee_id);
                if ($employee) {
                    $selectedMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->ledger_month)
                        && $this->ledger_month <= now()->format('Y-m')
                        ? $this->ledger_month
                        : now()->format('Y-m');
                    $monthStart = Carbon::createFromFormat('Y-m-d', $selectedMonth . '-01')->startOfDay();
                    $monthEnd = $monthStart->copy()->endOfMonth();
                    $reportEnd = $monthEnd->greaterThan(today()) ? today() : $monthEnd;
                    $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
                    $earningStart = $hireDate->greaterThan($monthStart) ? $hireDate : $monthStart;
                    $eligibleDays = $earningStart->greaterThan($reportEnd)
                        ? 0
                        : $earningStart->diffInDays($reportEnd) + 1;
                    $monthDays = $monthStart->daysInMonth;
                    $startOfMonth = $monthStart->toDateString();
                    $endOfMonth = $reportEnd->toDateString();

                    // 1. حساب أجرة اليوم الواحد وأجرة الساعة
                    $dailyRate = $employee->salary_type === 'hourly'
                        ? ($employee->hourly_rate * 8)
                        : ($employee->salary_type === 'daily'
                            ? $employee->basic_salary
                            : ($employee->salary_type === 'monthly'
                                ? ($employee->basic_salary / $monthDays)
                                : ($employee->basic_salary / 30)));

                    $hourlyRate = $employee->salary_type === 'hourly'
                        ? $employee->hourly_rate
                        : ($employee->salary_type === 'daily'
                            ? ($employee->basic_salary / 8)
                            : ($employee->salary_type === 'monthly'
                                ? ($employee->basic_salary / ($monthDays * 8))
                                : ($employee->basic_salary / 240)));

                    // 2. حساب عدد أيام الحضور الفعلية المسجلة (حاضر أو متأخر)
                    $actualWorkedDays = EmployeeAttendance::where('employee_id', $employee->id)
                        ->whereBetween('date', [$earningStart->toDateString(), $endOfMonth])
                        ->whereIn('status', ['present', 'late'])
                        ->count();

                    // 3. حساب عدد أيام الإجازات والعطل المدفوعة (سنوية، مرضية، طارئة)
                    $approvedLeaves = EmployeeLeave::where('employee_id', $employee->id)
                        ->where('status', 'approved')
                        ->where('start_date', '<=', $endOfMonth)
                        ->where('end_date', '>=', $earningStart->toDateString())
                        ->get();

                    $countLeaveDaysInPeriod = function (EmployeeLeave $leave) use ($earningStart, $reportEnd): int {
                        $leaveStart = Carbon::parse($leave->start_date)->startOfDay();
                        $leaveEnd = Carbon::parse($leave->end_date)->startOfDay();
                        $overlapStart = $leaveStart->greaterThan($earningStart) ? $leaveStart : $earningStart;
                        $overlapEnd = $leaveEnd->lessThan($reportEnd) ? $leaveEnd : $reportEnd;

                        return $overlapStart->greaterThan($overlapEnd)
                            ? 0
                            : (int) $overlapStart->diffInDays($overlapEnd) + 1;
                    };

                    $paidLeaveDays = $approvedLeaves
                        ->whereIn('type', ['annual', 'sick', 'emergency'])
                        ->sum($countLeaveDaysInPeriod);

                    // 4. حساب عدد أيام الإجازات غير المدفوعة الصريحة
                    $unpaidLeaveDays = $approvedLeaves
                        ->where('type', 'unpaid')
                        ->sum($countLeaveDaysInPeriod);

                    // 5. الأيام التي لم يسجل فيها دوام تعتبر غياباً تلقائياً باستثناء العطل والإجازات المدفوعة
                    $unrecordedAbsentDays = max(0, $eligibleDays - ($actualWorkedDays + $paidLeaveDays + $unpaidLeaveDays));
                    $totalAbsentOrUnpaidDays = $unpaidLeaveDays + $unrecordedAbsentDays;

                    // 6. قيمة الخصم المالي للغياب والإجازات غير المدفوعة
                    $salaryCap = $employee->salary_type === 'monthly'
                        ? ((float) $employee->basic_salary * ($eligibleDays / $monthDays))
                        : ($dailyRate * $eligibleDays);
                    $absenceDeductions = min($totalAbsentOrUnpaidDays * $dailyRate, $salaryCap);

                    // 7. الخصومات المباشرة والمغادرات المخصومة
                    $directDeductions = EmployeeDeduction::where('employee_id', $employee->id)
                        ->whereBetween('date', [$startOfMonth, $endOfMonth])
                        ->sum('amount');

                    $deductibleDepartureMinutes = EmployeeDeparture::where('employee_id', $employee->id)
                        ->where('is_deductible', true)
                        ->where('status', 'approved')
                        ->whereBetween('date', [$startOfMonth, $endOfMonth])
                        ->sum('minutes');
                    $departureDeductions = ($deductibleDepartureMinutes / 60) * $hourlyRate;

                    // إجمالي الخصومات المعتمدة
                    $totalDeductions = min(
                        $salaryCap,
                        $directDeductions + $absenceDeductions + $departureDeductions
                    );

                    // 8. المكافآت والبدلات للشهر الحالي
                    $bonuses = EmployeeBonus::where('employee_id', $employee->id)
                        ->whereBetween('date', [$startOfMonth, $endOfMonth])
                        ->sum('amount');

                    // 9. قسط السلفة القائم للشهر الحالي فقط
                    $activeAdvance = EmployeeAdvance::where('employee_id', $employee->id)
                        ->where('status', 'active')
                        ->where('start_installment_date', '<=', $endOfMonth)
                        ->first();

                    $advanceDeduction = 0;
                    if ($activeAdvance) {
                        $remainingAdvance = $activeAdvance->amount - $activeAdvance->paid_amount;
                        $advanceDeduction = min($activeAdvance->installment_amount, $remainingAdvance);
                    }
                    $advanceDeduction = min($advanceDeduction, max(0, $salaryCap - $totalDeductions));

                    // 10. الدفعات المالية المسددة فعلياً
                    $payments = EmployeePayment::where('employee_id', $employee->id)
                        ->whereBetween('payment_date', [$startOfMonth, $endOfMonth])
                        ->sum('amount');

                    // احتساب الراتب المستحق حسب أيام الدوام والإجازات المدفوعة
                    $earnedSalary = min(($actualWorkedDays + $paidLeaveDays) * $dailyRate, $salaryCap);
                    $totalEntitlements = $earnedSalary + $bonuses;
                    $netDue = max(0, $totalEntitlements - ($directDeductions + $departureDeductions) - $advanceDeduction);
                    $remainingBalance = $netDue - $payments;

                    $ledgerData = [
                        'employee' => $employee,
                        'month' => $selectedMonth,
                        'basic_salary' => $employee->basic_salary,
                        'daily_rate' => round($dailyRate, 2),
                        'actual_worked_days' => $actualWorkedDays,
                        'paid_leave_days' => $paidLeaveDays,
                        'unpaid_absent_days' => $totalAbsentOrUnpaidDays,
                        'earned_salary' => round($earnedSalary, 2),
                        'bonuses' => round($bonuses, 2),
                        'deductions' => round($totalDeductions, 2),
                        'advances' => round($advanceDeduction, 2),
                        'carryover_balance' => round((float) EmployeePayrollCarryover::where('employee_id', $employee->id)->where('status', 'pending')->sum('remaining_amount'), 2),
                        'payments' => round($payments, 2),
                        'net_due' => round($netDue, 2),
                        'remaining' => round($remainingBalance, 2),
                        'history' => EmployeePayment::where('employee_id', $employee->id)
                            ->whereBetween('payment_date', [$startOfMonth, $endOfMonth])
                            ->latest()
                            ->get(),
                    ];
                }
            }

            return [
                'employees' => Employee::latest()->paginate(10),
                'allEmployees' => Employee::where('is_active', true)->get(),
                'attendances' => $attendances,
                'departures' => EmployeeDeparture::with('employee')->latest()->paginate(10),
                'leaves' => EmployeeLeave::with('employee')->latest()->paginate(10),
                'advances' => EmployeeAdvance::with('employee')->latest()->paginate(10),
                'ledgerData' => $ledgerData,
                'periods' => PayrollPeriod::latest()->get(),
                'payrolls' => $this->selectedPeriodId
                    ? EmployeePayroll::with(['employee', 'items'])->where('payroll_period_id', $this->selectedPeriodId)->get()
                    : collect(),
            ];
        }
    };
    ?>

    <div class="p-6 space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between border-b pb-4 dark:border-gray-700">
            <div>
                <flux:heading size="xl">لوحة إدارة الموارد البشرية والرواتب (SPA)</flux:heading>
                <flux:subheading>إدارة شاملة للموظفين، الحضور، المغادرات، الإجازات، السلف، وكشف الحساب المالي</flux:subheading>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="flex space-x-2 space-x-reverse border-b pb-2 dark:border-gray-700 overflow-x-auto">
            <flux:button
                :variant="$activeTab === 'employees' ? 'primary' : 'ghost'"
                wire:click="$set('activeTab', 'employees')"
                icon="users">
                الموظفين
            </flux:button>

            <flux:button
                :variant="$activeTab === 'attendance' ? 'primary' : 'ghost'"
                wire:click="$set('activeTab', 'attendance')"
                icon="clock">
                الحضور والانصراف
            </flux:button>

            <flux:button
                :variant="$activeTab === 'departures' ? 'primary' : 'ghost'"
                wire:click="$set('activeTab', 'departures')"
                icon="arrow-right-start-on-rectangle">
                المغادرات
            </flux:button>

            <flux:button
                :variant="$activeTab === 'leaves' ? 'primary' : 'ghost'"
                wire:click="$set('activeTab', 'leaves')"
                icon="calendar-days">
                الإجازات والعطل
            </flux:button>

            <flux:button
                :variant="$activeTab === 'advances' ? 'primary' : 'ghost'"
                wire:click="$set('activeTab', 'advances')"
                icon="banknotes">
                السلف والقروض
            </flux:button>

            <flux:button
                :variant="$activeTab === 'ledger' ? 'primary' : 'ghost'"
                wire:click="$set('activeTab', 'ledger')"
                icon="document-text">
                كشف حساب الموظف
            </flux:button>

            <flux:button
                :variant="$activeTab === 'payrolls' ? 'primary' : 'ghost'"
                wire:click="$set('activeTab', 'payrolls')"
                icon="calculator">
                مسير الرواتب
            </flux:button>
        </div>

        <!-- Tab 1: الموظفين -->
        @if($activeTab === 'employees')
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <flux:heading size="lg">قائمة الموظفين</flux:heading>
                    <flux:button variant="primary" icon="user-plus" wire:click="$set('showEmployeeModal', true)">
                        إضافة موظف جديد
                    </flux:button>
                </div>

                <flux:card class="p-0 overflow-hidden">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700">
                            <tr>
                                <th class="p-3">الكود</th>
                                <th class="p-3">الاسم</th>
                                <th class="p-3">الهاتف</th>
                                <th class="p-3">نوع الراتب</th>
                                <th class="p-3">الراتب / سعر الساعة</th>
                                <th class="p-3">تاريخ التعيين</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y dark:divide-gray-700">
                            @forelse($employees as $emp)
                                <tr>
                                    <td class="p-3 font-mono">{{ $emp->employee_code }}</td>
                                    <td class="p-3 font-medium">{{ $emp->first_name }} {{ $emp->last_name }}</td>
                                    <td class="p-3">{{ $emp->phone ?? '-' }}</td>
                                    <td class="p-3"><flux:badge size="sm">{{ $emp->salary_type }}</flux:badge></td>
                                    <td class="p-3 font-bold">
                                        @if($emp->salary_type === 'hourly')
                                            {{ number_format($emp->hourly_rate, 2) }} / ساعة
                                        @else
                                            {{ number_format($emp->basic_salary, 2) }}
                                        @endif
                                    </td>
                                    <td class="p-3">{{ $emp->hire_date }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="p-4 text-center text-gray-500">لا يوجد موظفين مسجلين حالياً.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </flux:card>
            </div>
        @endif

        <!-- Tab 2: الحضور والانصراف -->
        @if($activeTab === 'attendance')
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <flux:card class="col-span-1 space-y-4">
                    <flux:heading size="lg">تسجيل حضور / غياب</flux:heading>
                    <form wire:submit="recordAttendance" class="space-y-3">
                        <flux:input type="date" label="التاريخ" wire:model.live="attendance_date" />

                        <flux:select label="الموظف" wire:model="employee_id">
                            <option value="">اختر الموظف...</option>
                            @foreach($allEmployees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->first_name }} {{ $emp->last_name }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select label="الحالة" wire:model="attendance_status">
                            <option value="present">حاضر</option>
                            <option value="late">متأخر</option>
                            <option value="absent">غائب</option>
                            <option value="leave">إجازة</option>
                        </flux:select>

                        <div class="grid grid-cols-2 gap-2">
                            <flux:input type="time" label="دخول" wire:model="check_in" />
                            <flux:input type="time" label="خروج" wire:model="check_out" />
                        </div>

                        <flux:button type="submit" variant="primary" class="w-full">حفظ السجل</flux:button>
                    </form>
                </flux:card>

                <flux:card class="col-span-2 space-y-3">
                    <flux:heading size="lg">سجل اليوم: {{ $attendance_date }}</flux:heading>
                    <table class="w-full text-right text-sm">
                        <thead>
                            <tr class="border-b dark:border-gray-700">
                                <th class="p-2">الموظف</th>
                                <th class="p-2">الدخول</th>
                                <th class="p-2">الخروج</th>
                                <th class="p-2">ساعات الدوام</th>
                                <th class="p-2">المغادرات</th>
                                <th class="p-2">التأخير</th>
                                <th class="p-2">الحالة</th>
                                <th class="p-2">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($attendances as $att)
                                <tr class="border-b dark:border-gray-800">
                                    <td class="p-2 font-medium">{{ $att->employee->first_name }} {{ $att->employee->last_name }}</td>
                                    <td class="p-2">{{ $att->check_in ?? '-' }}</td>
                                    <td class="p-2">{{ $att->check_out ?? '-' }}</td>
                                    <td class="p-2 font-bold text-indigo-600">
                                        {{ $att->worked_hours > 0 ? $att->worked_hours . ' ساعة' : '-' }}
                                    </td>
                                    <td class="p-2 text-amber-600">
                                        {{ $att->departures_hours > 0 ? $att->departures_hours . ' ساعة' : '-' }}
                                    </td>
                                    <td class="p-2 text-red-500">
                                        {{ $att->late_minutes > 0 ? $att->late_minutes . ' دقيقة' : 'لا يوجد' }}
                                    </td>
                                    <td class="p-2">
                                        <flux:badge size="sm" :color="$att->status === 'present' ? 'green' : ($att->status === 'absent' ? 'red' : 'amber')">
                                            {{ $att->status }}
                                        </flux:badge>
                                    </td>
                                    <td class="p-2">
                                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editAttendance({{ $att->id }})">
                                            تعديل
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="p-4 text-center text-gray-500">لا توجد سجلات لهذا اليوم.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </flux:card>
            </div>
        @endif

        <!-- Tab 3: المغادرات أثناء الدوام -->
        @if($activeTab === 'departures')
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <flux:heading size="lg">سجل مغادرات الموظفين</flux:heading>
                    <flux:button variant="primary" icon="plus" wire:click="$set('showDepartureModal', true)">
                        تسجيل مغادرة جديدة
                    </flux:button>
                </div>

                <flux:card>
                    <table class="w-full text-right text-sm">
                        <thead>
                            <tr class="border-b dark:border-gray-700">
                                <th class="p-2">الموظف</th>
                                <th class="p-2">التاريخ</th>
                                <th class="p-2">الوقت</th>
                                <th class="p-2">المدة (بالدقائق)</th>
                                <th class="p-2">تخصم من الراتب؟</th>
                                <th class="p-2">السبب</th>
                                <th class="p-2">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($departures as $dep)
                                <tr class="border-b dark:border-gray-800">
                                    <td class="p-2 font-medium">{{ $dep->employee->first_name }} {{ $dep->employee->last_name }}</td>
                                    <td class="p-2">{{ $dep->date }}</td>
                                    <td class="p-2">{{ $dep->start_time }} - {{ $dep->end_time }}</td>
                                    <td class="p-2 font-bold">{{ $dep->minutes }} دقيقة</td>
                                    <td class="p-2">
                                        <flux:badge size="sm" :color="$dep->is_deductible ? 'red' : 'zinc'">
                                            {{ $dep->is_deductible ? 'نعم (مخصومة)' : 'لا (غير مخصومة)' }}
                                        </flux:badge>
                                    </td>
                                    <td class="p-2 text-gray-500">{{ $dep->reason ?? '-' }}</td>
                                    <td class="p-2"><flux:badge size="sm" color="green">{{ $dep->status }}</flux:badge></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="p-4 text-center text-gray-500">لا توجد مغادرات مسجلة.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </flux:card>
            </div>
        @endif

        <!-- Tab 4: الإجازات والعطل -->
        @if($activeTab === 'leaves')
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <flux:heading size="lg">سجل الإجازات والعطلات</flux:heading>
                    <flux:button variant="primary" icon="plus" wire:click="$set('showLeaveModal', true)">
                        تقديم طلب إجازة
                    </flux:button>
                </div>

                <flux:card>
                    <table class="w-full text-right text-sm">
                        <thead>
                            <tr class="border-b dark:border-gray-700">
                                <th class="p-2">الموظف</th>
                                <th class="p-2">نوع الإجازة</th>
                                <th class="p-2">الفترة</th>
                                <th class="p-2">عدد الأيام</th>
                                <th class="p-2">السبب</th>
                                <th class="p-2">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($leaves as $leave)
                                <tr class="border-b dark:border-gray-800">
                                    <td class="p-2 font-medium">{{ $leave->employee->first_name }} {{ $leave->employee->last_name }}</td>
                                    <td class="p-2"><flux:badge size="sm">{{ $leave->type }}</flux:badge></td>
                                    <td class="p-2">{{ $leave->start_date }} إلى {{ $leave->end_date }}</td>
                                    <td class="p-2 font-bold text-indigo-600">{{ $leave->total_days }} يوم</td>
                                    <td class="p-2 text-gray-500">{{ $leave->reason ?? '-' }}</td>
                                    <td class="p-2"><flux:badge size="sm" color="green">{{ $leave->status }}</flux:badge></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="p-4 text-center text-gray-500">لا توجد إجازات مسجلة.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </flux:card>
            </div>
        @endif

        <!-- Tab 5: السلف -->
        @if($activeTab === 'advances')
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <flux:heading size="lg">سجل السلف والقروض</flux:heading>
                    <flux:button variant="primary" icon="plus" wire:click="$set('showAdvanceModal', true)">
                        صرف سلفة جديدة
                    </flux:button>
                </div>

                <flux:card>
                    <table class="w-full text-right text-sm">
                        <thead>
                            <tr class="border-b dark:border-gray-700">
                                <th class="p-2">الموظف</th>
                                <th class="p-2">المبلغ الكلي</th>
                                <th class="p-2">القسط الشهري</th>
                                <th class="p-2">المسدد</th>
                                <th class="p-2">تاريخ بداية الأقساط</th>
                                <th class="p-2">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($advances as $adv)
                                <tr class="border-b dark:border-gray-800">
                                    <td class="p-2 font-medium">{{ $adv->employee->first_name }} {{ $adv->employee->last_name }}</td>
                                    <td class="p-2 font-bold">{{ number_format($adv->amount, 2) }}</td>
                                    <td class="p-2 text-amber-600">{{ number_format($adv->installment_amount, 2) }}</td>
                                    <td class="p-2 text-green-600">{{ number_format($adv->paid_amount, 2) }}</td>
                                    <td class="p-2">{{ $adv->start_installment_date }}</td>
                                    <td class="p-2"><flux:badge size="sm">{{ $adv->status }}</flux:badge></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="p-4 text-center text-gray-500">لا توجد سلف مسجلة.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </flux:card>
            </div>
        @endif

        <!-- Tab 6: كشف حساب الموظف المالي (Employee Ledger) -->
        @if($activeTab === 'ledger')
            <div class="space-y-6">
                <div class="flex justify-between items-center">
                    <div class="w-1/3 space-y-3">
                        <flux:select label="اختر الموظف لعرض كشف الحساب" wire:model.live="ledger_employee_id">
                            <option value="">اختر الموظف...</option>
                            @foreach($allEmployees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->first_name }} {{ $emp->last_name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:input type="month" label="شهر كشف الحساب" wire:model.live="ledger_month" max="{{ now()->format('Y-m') }}" />
                        @error('ledger_month') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    @if($ledgerData)
                        <flux:button variant="primary" icon="banknotes" wire:click="$set('showPaymentModal', true)">
                            تسجيل دفعة / صرف راتب
                        </flux:button>
                    @endif
                </div>

                @if($ledgerData)
                    <flux:card class="space-y-4">
                        <flux:heading size="lg">كشف الحساب المالي للموظف: {{ $ledgerData['employee']->first_name }} {{ $ledgerData['employee']->last_name }} — {{ $ledgerData['month'] }}</flux:heading>

                        <!-- ملخص أيام الحضور والغياب والإجازات -->
                        <div class="grid grid-cols-4 gap-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-center text-sm font-medium">
                            <div>
                                <span class="block text-gray-500">أجر اليوم الواحد</span>
                                <span class="font-bold text-indigo-600">{{ $ledgerData['daily_rate'] }} شيكل</span>
                            </div>
                            <div>
                                <span class="block text-gray-500">أيام الحضور الفعلية</span>
                                <span class="font-bold text-green-600">{{ $ledgerData['actual_worked_days'] }} يوم</span>
                            </div>
                            <div>
                                <span class="block text-gray-500">إجازات مدفوعة الأجر</span>
                                <span class="font-bold text-blue-600">{{ $ledgerData['paid_leave_days'] }} يوم</span>
                            </div>
                            <div>
                                <span class="block text-gray-500">أيام غياب / عطل مخصومة</span>
                                <span class="font-bold text-red-600">{{ $ledgerData['unpaid_absent_days'] }} يوم</span>
                            </div>
                        </div>

                        <table class="w-full text-right text-sm border-t dark:border-gray-700">
                            <tbody class="divide-y dark:divide-gray-700">
                                <tr>
                                    <td class="p-3 font-medium">الراتب الأساسي التعاقدي</td>
                                    <td class="p-3 font-bold">{{ number_format($ledgerData['basic_salary'], 2) }} شيكل</td>
                                </tr>
                                <tr class="text-green-700 bg-green-50/50 dark:bg-green-900/10">
                                    <td class="p-3 font-medium">الراتب المكتسب (عن أيام الدوام {{ $ledgerData['actual_worked_days'] }} + الإجازات المدفوعة {{ $ledgerData['paid_leave_days'] }})</td>
                                    <td class="p-3 font-bold">{{ number_format($ledgerData['earned_salary'], 2) }} شيكل</td>
                                </tr>
                                <tr class="text-green-600">
                                    <td class="p-3 font-medium">المكافآت والبدلات (+)</td>
                                    <td class="p-3 font-bold">+{{ number_format($ledgerData['bonuses'], 2) }} شيكل</td>
                                </tr>
                                <tr class="text-red-600">
                                    <td class="p-3 font-medium">الخصومات المعتمدة (تتضمن أيام الغياب ومغادرات الحضور) (-)</td>
                                    <td class="p-3 font-bold">-{{ number_format($ledgerData['deductions'], 2) }} شيكل</td>
                                </tr>
                                <tr class="text-amber-600">
                                    <td class="p-3 font-medium">قسط السلفة للشهر الحالي (-)</td>
                                    <td class="p-3 font-bold">-{{ number_format($ledgerData['advances'], 2) }} شيكل</td>
                                </tr>
                                <tr class="bg-gray-50 dark:bg-gray-800 font-bold">
                                    <td class="p-3">صافي المستحق قبل الدفعات</td>
                                    <td class="p-3 text-indigo-600">{{ number_format($ledgerData['net_due'], 2) }} شيكل</td>
                                </tr>
                                <tr class="text-blue-600">
                                    <td class="p-3 font-medium">دفعتنا للموظف (المسدد) (-)</td>
                                    <td class="p-3 font-bold">-{{ number_format($ledgerData['payments'], 2) }} شيكل</td>
                                </tr>
                                <tr class="text-amber-700 bg-amber-50/50 dark:bg-amber-900/10">
                                    <td class="p-3 font-medium">دفعات راتب مقدمة مرحّلة (مكسور من الشهر السابق)</td>
                                    <td class="p-3 font-bold">{{ number_format($ledgerData['carryover_balance'], 2) }} شيكل</td>
                                </tr>
                                <tr class="bg-indigo-50 dark:bg-indigo-900/30 text-lg font-extrabold">
                                    <td class="p-3">المتبقي له / عليه</td>
                                    <td class="p-3 text-indigo-700 dark:text-indigo-300">{{ number_format($ledgerData['remaining'], 2) }} شيكل</td>
                                </tr>
                            </tbody>
                        </table>
                    </flux:card>

                    <!-- سجل الدفعات المسددة -->
                    <flux:card class="space-y-3">
                        <flux:heading size="md">سجل الدفعات المالية المسددة</flux:heading>
                        <table class="w-full text-right text-sm">
                            <thead>
                                <tr class="border-b dark:border-gray-700">
                                    <th class="p-2">التاريخ</th>
                                    <th class="p-2">المبلغ</th>
                                    <th class="p-2">طريقة الدفع</th>
                                    <th class="p-2">ملاحظات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($ledgerData['history'] as $pay)
                                    <tr class="border-b dark:border-gray-800">
                                        <td class="p-2">{{ $pay->payment_date }}</td>
                                        <td class="p-2 font-bold text-green-600">{{ number_format($pay->amount, 2) }} شيكل</td>
                                        <td class="p-2">{{ $pay->payment_method }}</td>
                                        <td class="p-2 text-gray-500">{{ $pay->notes ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="p-4 text-center text-gray-500">لا توجد دفعات مالية مسجلة بعد.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </flux:card>
                @else
                    <flux:card class="text-center p-12 text-gray-500">
                        يرجى اختيار موظف من القائمة أعلاه لاستعراض كشف حسابه المالي.
                    </flux:card>
                @endif
            </div>
        @endif

        <!-- Tab 7: مسير الرواتب -->
        @if($activeTab === 'payrolls')
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <flux:card class="col-span-1 space-y-3">
                    <div class="flex justify-between items-center">
                        <flux:heading size="lg">الفترات</flux:heading>
                        <flux:button size="sm" icon="plus" wire:click="$set('showPeriodModal', true)"></flux:button>
                    </div>
                    <div class="space-y-2">
                        @foreach($periods as $period)
                            <button
                                wire:click="$set('selectedPeriodId', {{ $period->id }})"
                                class="w-full text-right p-3 rounded-lg border transition {{ $selectedPeriodId == $period->id ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-200 dark:border-gray-700' }}"
                            >
                                <div class="font-bold text-sm">{{ $period->title }}</div>
                                <div class="text-xs text-gray-500">{{ $period->start_date }} - {{ $period->end_date }}</div>
                            </button>
                        @endforeach
                    </div>
                </flux:card>

                <div class="col-span-3 space-y-4">
                    @if($selectedPeriodId)
                        <div class="flex flex-wrap justify-between items-center gap-2">
                            <div>
                                <flux:heading size="lg">كشف حساب الرواتب</flux:heading>
                                @php($currentPeriod = $periods->firstWhere('id', (int) $selectedPeriodId))
                                @if($currentPeriod)
                                    <flux:badge size="sm" :color="$currentPeriod->status === 'approved' || $currentPeriod->status === 'paid' ? 'green' : ($currentPeriod->status === 'processing' ? 'blue' : 'zinc')">حالة المسير: {{ $currentPeriod->status }}</flux:badge>
                                @endif
                            </div>
                            <div class="flex gap-2">
                                @if($currentPeriod && !in_array($currentPeriod->status, ['approved', 'paid'], true))
                                    <flux:button variant="filled" icon="arrow-path" wire:click="calculatePayroll">
                                        احتساب / تجديد الرواتب
                                    </flux:button>
                                    <flux:button variant="primary" icon="check-circle" wire:click="approvePayroll" wire:confirm="هل أنت متأكد من اعتماد المسير؟ سيتم تثبيت أقساط السلف وترحيلات الرواتب.">
                                        اعتماد المسير
                                    </flux:button>
                                @endif
                            </div>
                        </div>

                        <flux:card>
                            <table class="w-full text-right text-sm">
                                <thead>
                                    <tr class="border-b dark:border-gray-700">
                                        <th class="p-2">الموظف</th>
                                        <th class="p-2">الأساسي</th>
                                        <th class="p-2">البدلات</th>
                                        <th class="p-2">الخصومات</th>
                                        <th class="p-2">قسط السلفة</th>
                                        <th class="p-2 font-bold">الصافي</th>
                                        <th class="p-2">المدفوع</th>
                                        <th class="p-2">الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($payrolls as $pr)
                                        <tr class="border-b dark:border-gray-800">
                                            <td class="p-2 font-medium">
                                                {{ $pr->employee->first_name }} {{ $pr->employee->last_name }}
                                                @foreach($pr->items->whereNotNull('employee_payroll_carryover_id') as $carryoverItem)
                                                    <div class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-400">
                                                        {{ $carryoverItem->title }}: {{ number_format($carryoverItem->amount, 2) }} شيكل
                                                    </div>
                                                @endforeach
                                            </td>
                                            <td class="p-2">{{ number_format($pr->basic_salary, 2) }}</td>
                                            <td class="p-2 text-green-600">+{{ number_format($pr->total_allowances, 2) }}</td>
                                            <td class="p-2 text-red-600">-{{ number_format($pr->total_deductions, 2) }}</td>
                                            <td class="p-2 text-amber-600">-{{ number_format($pr->advance_deduction, 2) }}</td>
                                            <td class="p-2 font-bold text-indigo-600">{{ number_format($pr->net_salary, 2) }}</td>
                                            <td class="p-2 text-green-600">{{ number_format($pr->paid_amount, 2) }}</td>
                                            <td class="p-2"><flux:badge size="sm" :color="$pr->payment_status === 'paid' ? 'green' : ($pr->payment_status === 'partial' ? 'amber' : 'zinc')">{{ $pr->payment_status }}</flux:badge></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8" class="p-4 text-center text-gray-500">اضغط "احتساب الرواتب" لتوليد البيانات.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </flux:card>
                    @else
                        <flux:card class="text-center p-8 text-gray-500">
                            اختر فترة راتب من القائمة الجانبية.
                        </flux:card>
                    @endif
                </div>
            </div>
        @endif

        <!-- Modals -->
        <!-- Modal 1: New Employee -->
        <flux:modal wire:model="showEmployeeModal">
            <form wire:submit="saveEmployee" class="space-y-4">
                <flux:heading size="lg">إضافة موظف جديد</flux:heading>
                <div class="grid grid-cols-2 gap-3">
                    <flux:input label="كود الموظف" wire:model="employee_code" />
                    <flux:input label="الاسم الأول" wire:model="first_name" />
                    <flux:input label="العائلة" wire:model="last_name" />
                    <flux:input label="الهاتف" wire:model="phone" />

                    <flux:select label="نوع الراتب" wire:model.live="salary_type">
                        <option value="monthly">شهري ثابت</option>
                        <option value="hourly">بالساعة</option>
                        <option value="daily">يومي</option>
                        <option value="production">بالإنتاج / القطعة</option>
                    </flux:select>

                    <flux:select label="نوع التوظيف" wire:model="employment_type">
                        <option value="full_time">دوام كامل</option>
                        <option value="part_time">دوام جزئي</option>
                        <option value="contract">عقد</option>
                        <option value="daily">يومية</option>
                    </flux:select>

                    @if($salary_type === 'hourly')
                        <flux:input type="number" step="0.01" label="سعر الساعة (أجرة الساعة)" wire:model="hourly_rate" placeholder="0.00" />
                    @elseif($salary_type === 'daily')
                        <flux:input type="number" step="0.01" label="الأجرة اليومية" wire:model="basic_salary" placeholder="0.00" />
                    @elseif($salary_type === 'production')
                        <flux:input type="number" step="0.01" label="سعر القطعة / الوحدة" wire:model="basic_salary" placeholder="0.00" />
                    @else
                        <flux:input type="number" step="0.01" label="الراتب الأساسي الشهري" wire:model="basic_salary" placeholder="0.00" />
                    @endif

                    <flux:input type="date" label="تاريخ التعيين" wire:model="hire_date" />
                </div>

                <div class="flex justify-end space-x-2 space-x-reverse">
                    <flux:button variant="ghost" wire:click="$set('showEmployeeModal', false)">إلغاء</flux:button>
                    <flux:button type="submit" variant="primary">حفظ الموظف</flux:button>
                </div>
            </form>
        </flux:modal>

        <!-- Modal 2: Edit Attendance -->
        <flux:modal wire:model="showEditAttendanceModal">
            <form wire:submit="updateAttendance" class="space-y-4">
                <flux:heading size="lg">تعديل سجل الحضور</flux:heading>
                <flux:select label="الحالة" wire:model="edit_status">
                    <option value="present">حاضر</option>
                    <option value="late">متأخر</option>
                    <option value="absent">غائب</option>
                    <option value="leave">إجازة</option>
                </flux:select>

                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="time" label="وقت الدخول" wire:model="edit_check_in" />
                    <flux:input type="time" label="وقت الخروج" wire:model="edit_check_out" />
                </div>

                <flux:input label="ملاحظات" wire:model="edit_notes" placeholder="سبب التعديل إن وجد" />

                <div class="flex justify-end space-x-2 space-x-reverse">
                    <flux:button variant="ghost" wire:click="$set('showEditAttendanceModal', false)">إلغاء</flux:button>
                    <flux:button type="submit" variant="primary">تحديث</flux:button>
                </div>
            </form>
        </flux:modal>

        <!-- Modal 3: New Departure -->
        <flux:modal wire:model="showDepartureModal">
            <form wire:submit="saveDeparture" class="space-y-4">
                <flux:heading size="lg">تسجيل مغادرة موظف</flux:heading>
                <flux:select label="الموظف" wire:model="departure_employee_id">
                    <option value="">اختر الموظف...</option>
                    @foreach($allEmployees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->first_name }} {{ $emp->last_name }}</option>
                    @endforeach
                </flux:select>

                <flux:input type="date" label="التاريخ" wire:model="departure_date" />

                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="time" label="من الساعة" wire:model="departure_start" />
                    <flux:input type="time" label="إلى الساعة" wire:model="departure_end" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="number" label="المدة الإجمالية (دقائق)" wire:model="departure_minutes" />
                    <flux:select label="نوع الاقتطاع" wire:model="is_deductible">
                        <option value="1">تخصم من الراتب</option>
                        <option value="0">إذن رسمي (غير مخصومة)</option>
                    </flux:select>
                </div>

                <flux:input label="سبب المغادرة" wire:model="departure_reason" placeholder="سبب خاص / طارئ" />

                <div class="flex justify-end space-x-2 space-x-reverse">
                    <flux:button variant="ghost" wire:click="$set('showDepartureModal', false)">إلغاء</flux:button>
                    <flux:button type="submit" variant="primary">حفظ المغادرة</flux:button>
                </div>
            </form>
        </flux:modal>

        <!-- Modal 4: New Leave -->
        <flux:modal wire:model="showLeaveModal">
            <form wire:submit="saveLeave" class="space-y-4">
                <flux:heading size="lg">تسجيل إجازة / عطلة لموظف</flux:heading>
                <flux:select label="الموظف" wire:model="leave_employee_id">
                    <option value="">اختر الموظف...</option>
                    @foreach($allEmployees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->first_name }} {{ $emp->last_name }}</option>
                    @endforeach
                </flux:select>

                <flux:select label="نوع الإجازة" wire:model="leave_type">
                    <option value="annual">سنوية مدفوعة</option>
                    <option value="sick">مرضية (مدفوعة)</option>
                    <option value="unpaid">بدون راتب (غير مدفوعة)</option>
                    <option value="emergency">طارئة (مدفوعة)</option>
                </flux:select>

                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="date" label="من تاريخ" wire:model="leave_start_date" />
                    <flux:input type="date" label="إلى تاريخ" wire:model="leave_end_date" />
                </div>

                <flux:input label="السبب / الملاحظات" wire:model="leave_reason" />

                <div class="flex justify-end space-x-2 space-x-reverse">
                    <flux:button variant="ghost" wire:click="$set('showLeaveModal', false)">إلغاء</flux:button>
                    <flux:button type="submit" variant="primary">حفظ الإجازة</flux:button>
                </div>
            </form>
        </flux:modal>

        <!-- Modal 5: New Advance -->
        <flux:modal wire:model="showAdvanceModal">
            <form wire:submit="saveAdvance" class="space-y-4">
                <flux:heading size="lg">صرف سلفة لموظف</flux:heading>
                <flux:select label="الموظف" wire:model="advance_employee_id">
                    <option value="">اختر الموظف...</option>
                    @foreach($allEmployees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->first_name }} {{ $emp->last_name }}</option>
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-3 gap-3">
                    <flux:input type="number" step="0.01" label="مبلغ السلفة" wire:model.live="advance_amount" placeholder="0.00" />
                    <flux:input type="number" min="1" label="عدد الأشهر" wire:model.live="advance_months" placeholder="1" />
                    <flux:input type="number" step="0.01" label="القسط الشهري (آلي)" wire:model="installment_amount" readonly class="bg-gray-100 font-bold" />
                </div>

                <flux:input type="date" label="تاريخ بداية الاقتطاع" wire:model="start_installment_date" />

                <div class="flex justify-end space-x-2 space-x-reverse">
                    <flux:button variant="ghost" wire:click="$set('showAdvanceModal', false)">إلغاء</flux:button>
                    <flux:button type="submit" variant="primary">حفظ وتأكيد</flux:button>
                </div>
            </form>
        </flux:modal>

        <!-- Modal 6: New Payment -->
        <flux:modal wire:model="showPaymentModal">
            <form wire:submit="savePayment" class="space-y-4">
                <flux:heading size="lg">سند صرف دفعة مالية للموظف</flux:heading>
                <flux:select label="الموظف" wire:model="payment_employee_id">
                    <option value="">اختر الموظف...</option>
                    @foreach($allEmployees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->first_name }} {{ $emp->last_name }}</option>
                    @endforeach
                </flux:select>

                <flux:select label="فترة الراتب المعتمدة" wire:model="payment_period_id">
                    <option value="">اختر فترة الراتب...</option>
                    @foreach($periods as $period)
                        @if(in_array($period->status, ['approved', 'paid'], true))
                            <option value="{{ $period->id }}">{{ $period->title }} ({{ $period->status }})</option>
                        @endif
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="number" step="0.01" label="المبلغ المسدد" wire:model="payment_amount" />
                    <flux:input type="date" label="تاريخ الصرف" wire:model="payment_date" />
                </div>

                <flux:select label="طريقة الدفع" wire:model="payment_method">
                    <option value="cash">نقداً (كاش)</option>
                    <option value="bank_transfer">تحويل بنكي</option>
                    <option value="check">شيك</option>
                </flux:select>

                <flux:input label="ملاحظات البيان" wire:model="payment_notes" placeholder="مثال: دفعة تحت حساب راتب شهر 10" />

                <div class="flex justify-end space-x-2 space-x-reverse">
                    <flux:button variant="ghost" wire:click="$set('showPaymentModal', false)">إلغاء</flux:button>
                    <flux:button type="submit" variant="primary">تأكيد الصرف</flux:button>
                </div>
            </form>
        </flux:modal>

        <!-- Modal 7: New Period -->
        <flux:modal wire:model="showPeriodModal">
            <form wire:submit="createPeriod" class="space-y-4">
                <flux:heading size="lg">إنشاء فترة راتب جديدة</flux:heading>
                <flux:input label="عنوان الفترة" placeholder="مثال: شهر 10 - 2026" wire:model="period_title" />
                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="date" label="من تاريخ" wire:model="period_start_date" />
                    <flux:input type="date" label="إلى تاريخ" wire:model="period_end_date" />
                </div>
                <div class="flex justify-end space-x-2 space-x-reverse">
                    <flux:button variant="ghost" wire:click="$set('showPeriodModal', false)">إلغاء</flux:button>
                    <flux:button type="submit" variant="primary">إنشاء</flux:button>
                </div>
            </form>
        </flux:modal>
    </div>
