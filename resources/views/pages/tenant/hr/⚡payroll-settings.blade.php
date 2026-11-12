<?php

use App\Models\Employee;
use App\Models\PayrollHoliday;
use App\Services\PayrollSettings;
use Livewire\Component;

/*
 * Payroll rules of the store: overtime multipliers, yearly leave entitlements and the public holidays calendar.
 * Changes apply to payrolls calculated from now on; approved payrolls are never recalculated.
 */
new class extends Component
{
    public float $overtime_multiplier = 1;

    public float $rest_day_multiplier = 1;

    public float $holiday_multiplier = 1;

    public float $annual_leave_days = 14;

    public float $sick_leave_days = 7;

    public string $holidayDate = '';

    public string $holidayName = '';

    public bool $holidayIsPaid = true;

    /** @var array<int, array{annual: string|float|null, sick: string|float|null}> */
    public array $leaveEntitlements = [];

    public ?string $savedMessage = null;

    public function mount(): void
    {
        $this->authorize('payroll.view');
        foreach (app(PayrollSettings::class)->all($this->tenantId()) as $key => $value) {
            $this->{$key} = $value;
        }
        $this->leaveEntitlements = Employee::query()->where('is_active', true)->get(['id', 'annual_leave_days', 'sick_leave_days'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => [
                'annual' => $employee->annual_leave_days !== null ? (float) $employee->annual_leave_days : null,
                'sick' => $employee->sick_leave_days !== null ? (float) $employee->sick_leave_days : null,
            ]])->all();
    }

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    public function saveSettings(): void
    {
        $this->authorize('payroll.edit');
        $validated = $this->validate([
            'overtime_multiplier' => ['required', 'numeric', 'min:1', 'max:5'],
            'rest_day_multiplier' => ['required', 'numeric', 'min:1', 'max:5'],
            'holiday_multiplier' => ['required', 'numeric', 'min:1', 'max:5'],
            'annual_leave_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'sick_leave_days' => ['required', 'numeric', 'min:0', 'max:365'],
        ]);
        app(PayrollSettings::class)->save($this->tenantId(), $validated);
        $this->savedMessage = 'تم حفظ إعدادات الرواتب. تُطبَّق على الرواتب التي تُحسب من الآن.';
    }

    public function addHoliday(): void
    {
        $this->authorize('payroll.edit');
        $this->validate([
            'holidayDate' => ['required', 'date'],
            'holidayName' => ['required', 'string', 'max:100'],
        ]);
        if (PayrollHoliday::query()->whereDate('date', $this->holidayDate)->exists()) {
            $this->addError('holidayDate', 'هذا اليوم مسجل كعطلة مسبقاً.');

            return;
        }
        PayrollHoliday::query()->create(['date' => $this->holidayDate, 'name' => trim($this->holidayName), 'is_paid' => $this->holidayIsPaid]);
        $this->reset(['holidayDate', 'holidayName']);
        $this->holidayIsPaid = true;
        $this->savedMessage = 'تمت إضافة العطلة.';
    }

    public function deleteHoliday(int $holidayId): void
    {
        $this->authorize('payroll.edit');
        PayrollHoliday::query()->findOrFail($holidayId)->delete();
        $this->savedMessage = 'تم حذف العطلة.';
    }

    public function saveLeaveEntitlement(int $employeeId): void
    {
        $this->authorize('payroll.edit');
        $employee = Employee::query()->findOrFail($employeeId);
        $values = $this->leaveEntitlements[$employeeId] ?? [];
        $this->validate([
            "leaveEntitlements.$employeeId.annual" => ['nullable', 'numeric', 'min:0', 'max:365'],
            "leaveEntitlements.$employeeId.sick" => ['nullable', 'numeric', 'min:0', 'max:365'],
        ]);
        $employee->update([
            'annual_leave_days' => ($values['annual'] ?? '') === '' || $values['annual'] === null ? null : (float) $values['annual'],
            'sick_leave_days' => ($values['sick'] ?? '') === '' || $values['sick'] === null ? null : (float) $values['sick'],
        ]);
        $this->savedMessage = 'تم حفظ رصيد إجازات '.$employee->first_name.'.';
    }

    public function with(): array
    {
        $settings = app(PayrollSettings::class);
        $today = today()->toDateString();

        return [
            'holidays' => PayrollHoliday::query()->whereYear('date', '>=', now()->year - 1)->orderBy('date')->get(),
            'employees' => Employee::query()->where('is_active', true)->orderBy('first_name')->get()
                ->map(fn (Employee $employee): array => [
                    'model' => $employee,
                    'annual_left' => $settings->remainingDays($employee, 'annual', $today),
                    'sick_left' => $settings->remainingDays($employee, 'sick', $today),
                ]),
        ];
    }
};
?>

<div class="space-y-6" dir="rtl" data-payroll-settings>
    @if ($savedMessage)
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">{{ $savedMessage }}</div>
    @endif

    <flux:card class="space-y-4">
        <flux:heading size="lg">أجر الوقت الإضافي</flux:heading>
        <p class="text-sm text-slate-500">أجر الساعة الإضافية = أجر الساعة العادي × المضاعف. يُحتسب الإضافي بعد موافقة المدير على دقائقه في الحضور.</p>
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input type="number" step="0.25" min="1" max="5" label="إضافي في يوم عمل عادي" wire:model="overtime_multiplier" />
            <flux:input type="number" step="0.25" min="1" max="5" label="العمل في يوم الراحة الأسبوعي" wire:model="rest_day_multiplier" />
            <flux:input type="number" step="0.25" min="1" max="5" label="العمل في عطلة رسمية" wire:model="holiday_multiplier" />
        </div>

        <flux:heading size="lg" class="pt-2">رصيد الإجازات السنوي (الافتراضي للمتجر)</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input type="number" step="0.5" min="0" label="أيام الإجازة السنوية المدفوعة" wire:model="annual_leave_days" />
            <flux:input type="number" step="0.5" min="0" label="أيام الإجازة المرضية المدفوعة" wire:model="sick_leave_days" />
        </div>
        <flux:button variant="primary" wire:click="saveSettings">حفظ الإعدادات</flux:button>
    </flux:card>

    <flux:card class="space-y-4">
        <flux:heading size="lg">العطل الرسمية</flux:heading>
        <p class="text-sm text-slate-500">لا يُخصم غياب في العطلة. العطلة المدفوعة تُدفع لأصحاب الأجر اليومي والساعة كيوم عمل، والعمل فيها يُحسب إضافياً بمضاعف العطلة.</p>
        <div class="grid items-end gap-3 sm:grid-cols-4">
            <flux:input type="date" label="التاريخ" wire:model="holidayDate" />
            <flux:input label="اسم العطلة" placeholder="مثل: عيد الفطر" wire:model="holidayName" />
            <flux:checkbox label="عطلة مدفوعة" wire:model="holidayIsPaid" />
            <flux:button variant="primary" icon="plus" wire:click="addHoliday">إضافة عطلة</flux:button>
        </div>
        <div class="app-table-scroll" role="region" aria-label="العطل الرسمية" tabindex="0">
            <table class="w-full text-right text-sm">
                <thead><tr class="border-b"><th class="p-2">التاريخ</th><th class="p-2">العطلة</th><th class="p-2">مدفوعة</th><th class="p-2"></th></tr></thead>
                <tbody>
                    @forelse ($holidays as $holiday)
                        <tr class="border-b" wire:key="holiday-{{ $holiday->id }}">
                            <td class="p-2 font-mono">{{ $holiday->date->toDateString() }}</td>
                            <td class="p-2">{{ $holiday->name }}</td>
                            <td class="p-2">{{ $holiday->is_paid ? 'نعم' : 'لا' }}</td>
                            <td class="p-2"><flux:button size="xs" variant="danger" wire:click="deleteHoliday({{ $holiday->id }})" wire:confirm="حذف هذه العطلة؟">حذف</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-4 text-center text-slate-500">لا توجد عطل مسجلة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>

    <flux:card class="space-y-4">
        <flux:heading size="lg">رصيد الإجازات لكل موظف</flux:heading>
        <p class="text-sm text-slate-500">اترك الخانة فارغة ليأخذ الموظف الرصيد الافتراضي للمتجر. المتبقي محسوب لهذه السنة.</p>
        <div class="app-table-scroll" role="region" aria-label="رصيد الإجازات" tabindex="0">
            <table class="w-full text-right text-sm">
                <thead><tr class="border-b"><th class="p-2">الموظف</th><th class="p-2">السنوية</th><th class="p-2">المتبقي</th><th class="p-2">المرضية</th><th class="p-2">المتبقي</th><th class="p-2"></th></tr></thead>
                <tbody>
                    @foreach ($employees as $row)
                        <tr class="border-b" wire:key="leave-entitlement-{{ $row['model']->id }}">
                            <td class="p-2 font-medium">{{ $row['model']->first_name }} {{ $row['model']->last_name }}</td>
                            <td class="p-2"><flux:input size="sm" type="number" step="0.5" min="0" placeholder="{{ $annual_leave_days + 0 }}" wire:model="leaveEntitlements.{{ $row['model']->id }}.annual" /></td>
                            <td class="p-2 font-bold text-indigo-700">{{ $row['annual_left'] + 0 }}</td>
                            <td class="p-2"><flux:input size="sm" type="number" step="0.5" min="0" placeholder="{{ $sick_leave_days + 0 }}" wire:model="leaveEntitlements.{{ $row['model']->id }}.sick" /></td>
                            <td class="p-2 font-bold text-indigo-700">{{ $row['sick_left'] + 0 }}</td>
                            <td class="p-2"><flux:button size="xs" wire:click="saveLeaveEntitlement({{ $row['model']->id }})">حفظ</flux:button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
