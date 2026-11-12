<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\Payment;
use App\Models\Shift;

/**
 * Records a salary or advance payment as a payment voucher, so the money it takes appears in
 * cash flow, daily cash and, when paid in cash by someone with an open shift, that shift's drawer.
 * The voucher is managed from payroll and is not counted again as an operating expense.
 */
class EmployeePaymentVoucher
{
    public function record(EmployeePayment $payment, Employee $employee, ?string $periodTitle = null): Payment
    {
        $tenantId = (int) $employee->tenant_id;
        $user = auth()->user();
        $branchId = $employee->branch_id ? (int) $employee->branch_id : $user?->activeBranchId();
        $method = match ((string) $payment->payment_method) {
            'check' => 'cheque',
            'card', 'bank_transfer', 'cheque' => (string) $payment->payment_method,
            default => 'cash',
        };
        $shiftId = $this->cashShiftId($tenantId, $branchId, $method);
        $name = trim($employee->first_name.' '.$employee->last_name);
        $isAdvance = $payment->payment_type === 'advance';

        return Payment::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'shift_id' => $shiftId,
            'created_by' => $user?->id,
            'party_id' => null,
            'type' => 'payment',
            'voucher_number' => ($isAdvance ? 'ADV-' : 'SAL-').str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
            'amount' => round((float) $payment->amount, 2),
            'payment_method' => $method,
            'payment_date' => $payment->payment_date,
            'employee_payment_id' => $payment->id,
            'notes' => ($isAdvance ? 'صرف سلفة للموظف ' : 'دفعة راتب للموظف ').$name
                .($periodTitle ? ' — '.$periodTitle : '')
                .($payment->notes ? ' — '.$payment->notes : ''),
        ]);
    }

    /** Cash paid by someone with an open shift in the branch comes out of that shift's drawer. */
    public function cashShiftId(int $tenantId, ?int $branchId, string $method): ?int
    {
        $user = auth()->user();
        if ($method !== 'cash' || ! $user || ! $branchId) {
            return null;
        }
        $shiftId = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->where('opened_by', $user->id)->where('status', 'open')->latest('id')->value('id');

        return $shiftId ? (int) $shiftId : null;
    }
}
