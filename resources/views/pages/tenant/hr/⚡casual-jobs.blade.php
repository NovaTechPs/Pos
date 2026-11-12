<?php

use App\Models\CasualJob;
use App\Models\Employee;
use App\Models\Payment;
use App\Services\EmployeePaymentVoucher;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/*
 * One-off jobs paid to casual workers (unloading a container, arranging the store, cleaning).
 * Each job is paid at once with a payment voucher: it leaves the cash box and counts as an operating expense.
 */
new class extends Component
{
    public string $workerName = '';

    public string $workerPhone = '';

    public ?int $employeeId = null;

    public string $title = '';

    public string $jobDate = '';

    public string $amount = '';

    public string $paymentMethod = 'cash';

    public string $notes = '';

    public ?string $savedMessage = null;

    public function mount(): void
    {
        $this->authorize('payroll.view');
        $this->jobDate = today()->toDateString();
    }

    public function updatedEmployeeId(): void
    {
        $employee = $this->employeeId ? Employee::query()->find($this->employeeId) : null;
        if ($employee) {
            $this->workerName = trim($employee->first_name.' '.$employee->last_name);
            $this->workerPhone = (string) $employee->phone;
        }
    }

    public function save(): void
    {
        $this->authorize('payroll.pay');
        $this->validate([
            'workerName' => ['required', 'string', 'max:150'],
            'workerPhone' => ['nullable', 'string', 'max:30'],
            'employeeId' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:150'],
            'jobDate' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'paymentMethod' => ['required', 'in:cash,bank_transfer,cheque'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = auth()->user();
        $tenantId = (int) $user->tenant_id;
        $branchId = $user->activeBranchId();
        $employeeId = $this->employeeId && Employee::query()->whereKey($this->employeeId)->exists() ? $this->employeeId : null;

        DB::transaction(function () use ($user, $tenantId, $branchId, $employeeId): void {
            $job = CasualJob::query()->create([
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'employee_id' => $employeeId,
                'worker_name' => trim($this->workerName), 'worker_phone' => trim($this->workerPhone) ?: null,
                'title' => trim($this->title), 'job_date' => $this->jobDate, 'amount' => round((float) $this->amount, 2),
                'payment_method' => $this->paymentMethod, 'notes' => trim($this->notes) ?: null, 'created_by' => $user->id,
            ]);
            $payment = Payment::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'shift_id' => app(EmployeePaymentVoucher::class)->cashShiftId($tenantId, $branchId, $this->paymentMethod),
                'created_by' => $user->id,
                'type' => 'payment',
                'voucher_number' => 'JOB-'.str_pad((string) $job->id, 6, '0', STR_PAD_LEFT),
                'amount' => $job->amount,
                'payment_method' => $this->paymentMethod,
                'payment_date' => $this->jobDate,
                'notes' => 'أجرة عمل مؤقت: '.$job->title.' — '.$job->worker_name.($job->notes ? ' — '.$job->notes : ''),
            ]);
            $job->update(['payment_id' => $payment->id]);
        });

        $this->savedMessage = 'تم تسجيل أجرة '.trim($this->workerName).' ودفعها بسند صرف.';
        $this->reset(['workerName', 'workerPhone', 'employeeId', 'title', 'amount', 'notes']);
    }

    public function delete(int $jobId): void
    {
        $this->authorize('payroll.edit');
        DB::transaction(function () use ($jobId): void {
            $job = CasualJob::query()->lockForUpdate()->findOrFail($jobId);
            $paymentId = $job->payment_id;
            $job->update(['payment_id' => null]);
            $job->delete();
            Payment::query()->whereKey($paymentId)->first()?->delete();
        });
        $this->savedMessage = 'تم حذف العمل وسند صرفه.';
    }

    public function with(): array
    {
        return [
            'employees' => Employee::query()->where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'jobs' => CasualJob::query()->latest('job_date')->latest('id')->limit(100)->get(),
            'monthTotal' => (float) CasualJob::query()->whereYear('job_date', now()->year)->whereMonth('job_date', now()->month)->sum('amount'),
        ];
    }
};
?>

<div class="space-y-6" dir="rtl" data-casual-jobs>
    @if ($savedMessage)
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">{{ $savedMessage }}</div>
    @endif

    <flux:card class="space-y-4">
        <flux:heading size="lg">أجرة عامل مؤقت</flux:heading>
        <p class="text-sm text-slate-500">للأعمال لمرة واحدة مثل تحميل كونتينر أو ترتيب مخزن أو تنظيف. تُدفع فوراً بسند صرف من الصندوق وتُحسب مصروفاً تشغيلياً.</p>
        <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select label="موظف مسجّل (اختياري)" wire:model.live="employeeId">
                <option value="">عامل من الخارج</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>
                @endforeach
            </flux:select>
            <flux:input label="اسم العامل" wire:model="workerName" />
            <flux:input label="الهاتف" wire:model="workerPhone" />
            <flux:input label="العمل" placeholder="مثل: تحميل كونتينر" wire:model="title" />
            <flux:input type="date" label="التاريخ" wire:model="jobDate" />
            <flux:input type="number" step="0.01" min="0" label="الأجرة" wire:model="amount" />
            <flux:select label="طريقة الدفع" wire:model="paymentMethod">
                <option value="cash">نقداً</option>
                <option value="bank_transfer">تحويل بنكي</option>
                <option value="cheque">شيك</option>
            </flux:select>
            <flux:input label="ملاحظات" wire:model="notes" />
        </div>
        <flux:button variant="primary" wire:click="save">تسجيل ودفع</flux:button>
    </flux:card>

    <flux:card class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">الأعمال المؤقتة</flux:heading>
            <span class="text-sm font-bold text-slate-600">هذا الشهر: {{ number_format($monthTotal, 2) }}</span>
        </div>
        <div class="app-table-scroll" role="region" aria-label="الأعمال المؤقتة" tabindex="0">
            <table class="w-full text-right text-sm">
                <thead><tr class="border-b"><th class="p-2">التاريخ</th><th class="p-2">العامل</th><th class="p-2">العمل</th><th class="p-2">الأجرة</th><th class="p-2">السند</th><th class="p-2"></th></tr></thead>
                <tbody>
                    @forelse ($jobs as $job)
                        <tr class="border-b" wire:key="casual-job-{{ $job->id }}">
                            <td class="p-2 font-mono">{{ $job->job_date->toDateString() }}</td>
                            <td class="p-2">{{ $job->worker_name }}@if ($job->worker_phone)<span class="block text-xs text-slate-500" dir="ltr">{{ $job->worker_phone }}</span>@endif</td>
                            <td class="p-2">{{ $job->title }}</td>
                            <td class="p-2 font-bold">{{ number_format((float) $job->amount, 2) }}</td>
                            <td class="p-2 font-mono text-xs">JOB-{{ str_pad((string) $job->id, 6, '0', STR_PAD_LEFT) }}</td>
                            <td class="p-2"><flux:button size="xs" variant="danger" wire:click="delete({{ $job->id }})" wire:confirm="حذف هذا العمل وسند صرفه؟">حذف</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-4 text-center text-slate-500">لا توجد أعمال مسجلة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
