<?php

use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeeDeduction;
use App\Models\PayrollPeriod;
use Livewire\Component;

/*
 * Bonuses and deductions on an employee's salary, including penalties for negligence or damage.
 * They enter the payroll of the period that covers their date; entries in an approved payroll are locked.
 */
new class extends Component
{
    public const CATEGORIES = ['negligence' => 'عقوبة إهمال', 'damage' => 'كسر أو إتلاف', 'lateness' => 'تأخير', 'other' => 'خصم آخر'];

    public string $kind = 'deduction';

    public ?int $employeeId = null;

    public string $category = 'negligence';

    public string $amount = '';

    public string $date = '';

    public string $reason = '';

    public ?string $savedMessage = null;

    public function mount(): void
    {
        $this->authorize('payroll.view');
        $this->date = today()->toDateString();
    }

    public function save(): void
    {
        $this->authorize('payroll.edit');
        $this->validate([
            'kind' => ['required', 'in:bonus,deduction'],
            'employeeId' => ['required', 'integer'],
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $employee = Employee::query()->findOrFail($this->employeeId);
        if ($this->isLocked($employee->id, $this->date)) {
            $this->addError('date', 'راتب هذه الفترة معتمد؛ سجّلها في فترة لاحقة.');

            return;
        }
        $attributes = ['employee_id' => $employee->id, 'amount' => round((float) $this->amount, 2), 'date' => $this->date, 'reason' => trim($this->reason)];
        $this->kind === 'bonus'
            ? EmployeeBonus::query()->create($attributes)
            : EmployeeDeduction::query()->create($attributes + ['category' => $this->category]);
        $this->reset(['amount', 'reason']);
        $this->savedMessage = ($this->kind === 'bonus' ? 'تمت إضافة المكافأة' : 'تم تسجيل الخصم').' على راتب '.$employee->first_name.'.';
    }

    public function delete(string $kind, int $id): void
    {
        $this->authorize('payroll.edit');
        $entry = $kind === 'bonus' ? EmployeeBonus::query()->findOrFail($id) : EmployeeDeduction::query()->findOrFail($id);
        if ($this->isLocked((int) $entry->employee_id, (string) $entry->date)) {
            $this->savedMessage = null;
            $this->addError('date', 'لا يمكن حذف بند داخل راتب معتمد.');

            return;
        }
        $entry->delete();
        $this->savedMessage = 'تم الحذف.';
    }

    /** An entry is locked once the employee's payroll for its date has been approved or paid. */
    private function isLocked(int $employeeId, string $date): bool
    {
        return PayrollPeriod::query()->whereIn('status', ['approved', 'paid'])
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->whereHas('employeePayrolls', fn ($query) => $query->where('employee_id', $employeeId))->exists();
    }

    public function with(): array
    {
        $bonuses = EmployeeBonus::query()->with('employee')->latest('date')->latest('id')->limit(50)->get()
            ->map(fn (EmployeeBonus $bonus): array => ['kind' => 'bonus', 'model' => $bonus, 'label' => 'مكافأة']);
        $deductions = EmployeeDeduction::query()->with('employee')->latest('date')->latest('id')->limit(50)->get()
            ->map(fn (EmployeeDeduction $deduction): array => ['kind' => 'deduction', 'model' => $deduction, 'label' => self::CATEGORIES[$deduction->category] ?? 'خصم']);

        return [
            'employees' => Employee::query()->where('is_active', true)->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'entries' => $bonuses->concat($deductions)->sortByDesc(fn (array $row): string => $row['model']->date.'-'.str_pad((string) $row['model']->id, 10, '0', STR_PAD_LEFT))->values(),
        ];
    }
};
?>

<div class="space-y-6" dir="rtl" data-payroll-adjustments>
    @if ($savedMessage)
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">{{ $savedMessage }}</div>
    @endif

    <flux:card class="space-y-4">
        <flux:heading size="lg">مكافأة أو خصم على الراتب</flux:heading>
        <div class="grid items-end gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <flux:select label="النوع" wire:model.live="kind">
                <option value="deduction">خصم / عقوبة</option>
                <option value="bonus">مكافأة</option>
            </flux:select>
            <flux:select label="الموظف" wire:model="employeeId">
                <option value="">اختر الموظف</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>
                @endforeach
            </flux:select>
            @if ($kind === 'deduction')
                <flux:select label="سبب الخصم" wire:model="category">
                    @foreach (self::CATEGORIES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            @endif
            <flux:input type="number" step="0.01" min="0" label="المبلغ" wire:model="amount" />
            <flux:input type="date" label="التاريخ" wire:model="date" />
            <flux:input label="التفاصيل" placeholder="مثل: كسر رف زجاج" wire:model="reason" />
        </div>
        <flux:button variant="primary" wire:click="save">حفظ</flux:button>
    </flux:card>

    <flux:card>
        <div class="app-table-scroll" role="region" aria-label="المكافآت والخصومات" tabindex="0">
            <table class="w-full text-right text-sm">
                <thead><tr class="border-b"><th class="p-2">التاريخ</th><th class="p-2">الموظف</th><th class="p-2">النوع</th><th class="p-2">التفاصيل</th><th class="p-2">المبلغ</th><th class="p-2"></th></tr></thead>
                <tbody>
                    @forelse ($entries as $row)
                        <tr class="border-b" wire:key="adjustment-{{ $row['kind'] }}-{{ $row['model']->id }}">
                            <td class="p-2 font-mono">{{ \Illuminate\Support\Carbon::parse($row['model']->date)->toDateString() }}</td>
                            <td class="p-2">{{ $row['model']->employee?->first_name }} {{ $row['model']->employee?->last_name }}</td>
                            <td class="p-2"><flux:badge size="sm" :color="$row['kind'] === 'bonus' ? 'green' : 'red'">{{ $row['label'] }}</flux:badge></td>
                            <td class="p-2 text-slate-600">{{ $row['model']->reason }}</td>
                            <td class="p-2 font-bold {{ $row['kind'] === 'bonus' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $row['kind'] === 'bonus' ? '+' : '−' }}{{ number_format((float) $row['model']->amount, 2) }}</td>
                            <td class="p-2"><flux:button size="xs" variant="danger" wire:click="delete('{{ $row['kind'] }}', {{ $row['model']->id }})" wire:confirm="حذف هذا البند؟">حذف</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-4 text-center text-slate-500">لا توجد مكافآت أو خصومات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
