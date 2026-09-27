<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Shift;
use App\Models\Order;
use App\Models\DailySettlement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public string $date = '';

    public float $total_sales = 0.0;
    public float $total_returns = 0.0;

    public float $expected_cash = 0.0;
    public float $actual_cash = 0.0;
    public float $difference = 0.0;

    public string $notes = '';

    public int $open_shifts_count = 0;
    public int $closed_shifts_count = 0;

    public bool $is_settled = false;

    public ?DailySettlement $existingSettlement = null;

    /**
     * شيفتات اليوم للمراجعة.
     */
    public $dayShifts = [];

    /**
     * رسائل الواجهة.
     */
    public ?string $errorMessage = null;
    public ?string $successMessage = null;


    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->date = now()->toDateString();

        $this->loadData();
    }


    /*
    |--------------------------------------------------------------------------
    | Tenant / Branch
    |--------------------------------------------------------------------------
    */

    protected function getTenantId(): ?int
    {
        $user = Auth::user();

        return session('active_tenant_id')
            ?? $user?->tenant_id;
    }

    protected function getBranchId(): ?int
    {
        $user = Auth::user();

        return session('active_branch_id')
            ?? $user?->branch_id;
    }


    /*
    |--------------------------------------------------------------------------
    | Date
    |--------------------------------------------------------------------------
    */

    public function updatedDate(): void
    {
        $this->clearMessages();

        $this->loadData();
    }


    /*
    |--------------------------------------------------------------------------
    | Load Daily Data
    |--------------------------------------------------------------------------
    */

    public function loadData(): void
    {
        $tenantId = $this->getTenantId();
        $branchId = $this->getBranchId();

        /*
        |--------------------------------------------------------------------------
        | Validate Tenant
        |--------------------------------------------------------------------------
        */

        if (!$tenantId) {
            $this->resetDailyState();

            $this->errorMessage = 'تعذر تحديد المؤسسة الحالية.';

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Branch
        |--------------------------------------------------------------------------
        */

        if (!$branchId) {
            $this->resetDailyState();

            $this->errorMessage = 'تعذر تحديد الفرع الحالي.';

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Load Today's Shifts
        |--------------------------------------------------------------------------
        */

        $this->dayShifts = Shift::query()
            ->with('user')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereDate('opened_at', $this->date)
            ->latest('opened_at')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Existing Settlement
        |--------------------------------------------------------------------------
        */

        $this->existingSettlement = DailySettlement::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereDate('settlement_date', $this->date)
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Already Settled
        |--------------------------------------------------------------------------
        */

        if ($this->existingSettlement) {
            $this->loadExistingSettlement();

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Fresh Day
        |--------------------------------------------------------------------------
        */

        $this->is_settled = false;

        /*
        |--------------------------------------------------------------------------
        | Open / Closed Shifts
        |--------------------------------------------------------------------------
        */

        $openShifts = $this->dayShifts
            ->where('status', 'open');

        $closedShifts = $this->dayShifts
            ->where('status', 'closed');

        $this->open_shifts_count = $openShifts->count();
        $this->closed_shifts_count = $closedShifts->count();

        /*
        |--------------------------------------------------------------------------
        | Closed Shift IDs
        |--------------------------------------------------------------------------
        */

        $closedShiftIds = $closedShifts
            ->pluck('id')
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Sales / Returns
        |--------------------------------------------------------------------------
        |
        | نعتمد Orders كمصدر أساسي للمبيعات والمرتجعات.
        |
        */

        $this->total_sales = 0.0;
        $this->total_returns = 0.0;

        if (!empty($closedShiftIds)) {

            $this->total_sales = (float) Order::query()
                ->whereIn('shift_id', $closedShiftIds)
                ->where('type', 'sale')
                ->sum('total_amount');

            $this->total_returns = (float) Order::query()
                ->whereIn('shift_id', $closedShiftIds)
                ->where('type', 'return')
                ->sum('total_amount');
        }

        /*
        |--------------------------------------------------------------------------
        | Expected Cash
        |--------------------------------------------------------------------------
        |
        | حسب الهيكل الحالي:
        | expected_cash = مجموع actual_cash للشيفتات المغلقة.
        |
        */

        $this->expected_cash = (float) $closedShifts
            ->sum('actual_cash');

        /*
        |--------------------------------------------------------------------------
        | Default Actual Cash
        |--------------------------------------------------------------------------
        |
        | عند فتح يوم غير معتمد:
        | نبدأ من المتوقع، والمستخدم يستطيع تعديله.
        |
        */

        $this->actual_cash = $this->expected_cash;

        /*
        |--------------------------------------------------------------------------
        | Difference
        |--------------------------------------------------------------------------
        */

        $this->calculateDifference();
    }


    /*
    |--------------------------------------------------------------------------
    | Load Existing Settlement
    |--------------------------------------------------------------------------
    */

    protected function loadExistingSettlement(): void
    {
        if (!$this->existingSettlement) {
            return;
        }

        $this->is_settled = true;

        /*
        |--------------------------------------------------------------------------
        | Saved Sales
        |--------------------------------------------------------------------------
        */

        $this->total_sales = (float) (
            $this->existingSettlement->total_sales ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Saved Returns
        |--------------------------------------------------------------------------
        */

        $this->total_returns = (float) (
            $this->existingSettlement->total_returns ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Expected Cash
        |--------------------------------------------------------------------------
        |
        | ندعم expected_cash الجديد،
        | ونرجع إلى total_cash للتوافق مع الهيكل القديم.
        |
        */

        $this->expected_cash = (float) (
            $this->existingSettlement->expected_cash
            ?? $this->existingSettlement->total_cash
            ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Actual Cash
        |--------------------------------------------------------------------------
        */

        $this->actual_cash = (float) (
            $this->existingSettlement->actual_cash
            ?? $this->existingSettlement->total_cash
            ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | Difference
        |--------------------------------------------------------------------------
        */

        $this->difference = (float) (
            $this->existingSettlement->cash_difference
            ?? (
                $this->actual_cash - $this->expected_cash
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Notes
        |--------------------------------------------------------------------------
        */

        $this->notes = (string) (
            $this->existingSettlement->notes ?? ''
        );

        /*
        |--------------------------------------------------------------------------
        | Counts
        |--------------------------------------------------------------------------
        */

        $this->open_shifts_count = 0;

        $this->closed_shifts_count = $this->dayShifts
            ->where('status', 'closed')
            ->count();
    }


    /*
    |--------------------------------------------------------------------------
    | Actual Cash
    |--------------------------------------------------------------------------
    */

    public function updatedActualCash(): void
    {
        if ($this->is_settled) {
            return;
        }

        $this->calculateDifference();
    }


    /*
    |--------------------------------------------------------------------------
    | Difference Calculation
    |--------------------------------------------------------------------------
    */

    public function calculateDifference(): void
    {
        $this->difference =
            round(
                (float) $this->actual_cash
                - (float) $this->expected_cash,
                2
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Save Settlement
    |--------------------------------------------------------------------------
    */

    public function saveSettlement(): void
    {
        $this->clearMessages();

        /*
        |--------------------------------------------------------------------------
        | Already Settled
        |--------------------------------------------------------------------------
        */

        if ($this->is_settled) {
            $this->errorMessage =
                'هذه اليومية معتمدة مسبقًا ولا يمكن اعتمادها مرة أخرى.';

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | User
        |--------------------------------------------------------------------------
        */

        $user = Auth::user();

        if (!$user) {
            $this->errorMessage =
                'تعذر تحديد المستخدم الحالي.';

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Tenant / Branch
        |--------------------------------------------------------------------------
        */

        $tenantId = $this->getTenantId();
        $branchId = $this->getBranchId();
        $userId = $user->id;

        if (!$tenantId) {
            $this->errorMessage =
                'تعذر تحديد المؤسسة الحالية.';

            return;
        }

        if (!$branchId) {
            $this->errorMessage =
                'تعذر تحديد الفرع الحالي.';

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Actual Cash
        |--------------------------------------------------------------------------
        */

        if ($this->actual_cash < 0) {
            $this->errorMessage =
                'المبلغ الفعلي لا يمكن أن يكون سالبًا.';

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Recheck Open Shifts
        |--------------------------------------------------------------------------
        |
        | مهم جدًا: لا نعتمد على الرقم المحمل في الواجهة فقط.
        |
        */

        $this->open_shifts_count = Shift::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereDate('opened_at', $this->date)
            ->where('status', 'open')
            ->count();

        if ($this->open_shifts_count > 0) {
            $this->errorMessage =
                "تعذر اعتماد اليومية! يوجد {$this->open_shifts_count} شيفت مفتوح، يرجى إغلاقه أولًا.";

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Transaction
        |--------------------------------------------------------------------------
        */

        try {

            DB::transaction(function () use (
                $tenantId,
                $branchId,
                $userId
            ) {

                /*
                |--------------------------------------------------------------------------
                | Prevent Duplicate Settlement
                |--------------------------------------------------------------------------
                */

                $existing = DailySettlement::query()
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $branchId)
                    ->whereDate('settlement_date', $this->date)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new \RuntimeException(
                        'هذه اليومية تم اعتمادها مسبقًا.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Lock Closed Shifts
                |--------------------------------------------------------------------------
                */

                $closedShifts = Shift::query()
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $branchId)
                    ->whereDate('opened_at', $this->date)
                    ->where('status', 'closed')
                    ->lockForUpdate()
                    ->get();

                /*
                |--------------------------------------------------------------------------
                | Recalculate Inside Transaction
                |--------------------------------------------------------------------------
                */

                $closedShiftIds = $closedShifts
                    ->pluck('id')
                    ->values()
                    ->all();

                $totalSales = 0.0;
                $totalReturns = 0.0;

                if (!empty($closedShiftIds)) {

                    $totalSales = (float) Order::query()
                        ->whereIn('shift_id', $closedShiftIds)
                        ->where('type', 'sale')
                        ->sum('total_amount');

                    $totalReturns = (float) Order::query()
                        ->whereIn('shift_id', $closedShiftIds)
                        ->where('type', 'return')
                        ->sum('total_amount');
                }

                $expectedCash = (float) $closedShifts
                    ->sum('actual_cash');

                $actualCash = round(
                    (float) $this->actual_cash,
                    2
                );

                $difference = round(
                    $actualCash - $expectedCash,
                    2
                );

                /*
                |--------------------------------------------------------------------------
                | Create Settlement
                |--------------------------------------------------------------------------
                */

                $settlement = DailySettlement::create([
                    'tenant_id'          => $tenantId,
                    'branch_id'          => $branchId,
                    'closed_by'          => $userId,
                    'settlement_date'    => $this->date,

                    'total_sales'        => $totalSales,
                    'total_returns'      => $totalReturns,

                    /*
                    |--------------------------------------------------------------------------
                    | Financial Values
                    |--------------------------------------------------------------------------
                    */

                    'expected_cash'      => $expectedCash,
                    'actual_cash'        => $actualCash,
                    'cash_difference'    => $difference,

                    /*
                    | التوافق مع العمود القديم إن كان موجودًا.
                    */
                    'total_cash'         => $actualCash,

                    'total_card'         => 0.00,

                    'total_shifts_count' => $closedShifts->count(),

                    'closed_at'          => now(),

                    'notes'              => trim($this->notes) ?: null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Link Closed Shifts
                |--------------------------------------------------------------------------
                */

                if ($closedShifts->isNotEmpty()) {

                    Shift::query()
                        ->whereIn(
                            'id',
                            $closedShifts->pluck('id')
                        )
                        ->whereNull('daily_settlement_id')
                        ->update([
                            'daily_settlement_id' => $settlement->id,
                        ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Update Component State
                |--------------------------------------------------------------------------
                */

                $this->existingSettlement = $settlement;

                $this->total_sales = $totalSales;
                $this->total_returns = $totalReturns;

                $this->expected_cash = $expectedCash;
                $this->actual_cash = $actualCash;
                $this->difference = $difference;

                $this->closed_shifts_count =
                    $closedShifts->count();

                $this->open_shifts_count = 0;

                $this->is_settled = true;
            });

            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            $this->successMessage =
                'تم اعتماد اليومية وحفظ التسوية المالية وربط الشيفتات بنجاح.';

        } catch (\RuntimeException $e) {

            $this->errorMessage = $e->getMessage();

        } catch (\Throwable $e) {

            report($e);

            $this->errorMessage =
                'حدث خطأ أثناء اعتماد اليومية. يرجى المحاولة مرة أخرى.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Reset
    |--------------------------------------------------------------------------
    */

    protected function resetDailyState(): void
    {
        $this->dayShifts = [];

        $this->existingSettlement = null;

        $this->total_sales = 0.0;
        $this->total_returns = 0.0;

        $this->expected_cash = 0.0;
        $this->actual_cash = 0.0;
        $this->difference = 0.0;

        $this->open_shifts_count = 0;
        $this->closed_shifts_count = 0;

        $this->is_settled = false;

        $this->notes = '';
    }


    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    protected function clearMessages(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;
    }


    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">

    <div
        class="min-h-screen bg-slate-100 p-3 font-sans select-none sm:p-4 md:p-6"
    >

        <div class="mx-auto max-w-6xl space-y-4">


            {{-- ========================================================= --}}
            {{-- Header --}}
            {{-- ========================================================= --}}

            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >

                <div
                    class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between md:p-5"
                >

                    <div class="min-w-0">

                        <div class="flex items-center gap-2">

                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-xl"
                            >
                                📑
                            </div>

                            <div class="min-w-0">

                                <h1
                                    class="truncate text-base font-black text-slate-800 sm:text-lg"
                                >
                                    التسوية وإغلاق اليومية المالية
                                </h1>

                                <p class="mt-0.5 text-[11px] leading-5 text-slate-500 sm:text-xs">
                                    مراجعة الشيفتات ومطابقة النقد واعتماد اليومية المالية.
                                </p>

                            </div>

                        </div>

                    </div>


                    {{-- Date --}}

                    <div
                        class="flex w-full items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2 sm:w-auto"
                    >

                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-sm shadow-sm"
                        >
                            📅
                        </div>

                        <div class="min-w-0 flex-1 sm:flex-none">

                            <label
                                class="mb-0.5 block text-[10px] font-bold text-slate-500"
                            >
                                تاريخ اليومية
                            </label>

                            <input
                                type="date"
                                wire:model.live="date"
                                @disabled($is_settled)
                                class="w-full border-0 bg-transparent p-0 text-xs font-black font-mono text-slate-800 outline-none focus:ring-0 disabled:cursor-not-allowed disabled:opacity-60 sm:w-32"
                            >

                        </div>

                    </div>

                </div>

            </div>


            {{-- ========================================================= --}}
            {{-- Alerts --}}
            {{-- ========================================================= --}}

            @if ($errorMessage)

                <div
                    class="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-3.5 text-xs text-rose-800 shadow-sm"
                    role="alert"
                >

                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-rose-100"
                    >
                        ⚠️
                    </div>

                    <div class="min-w-0 flex-1">

                        <div class="font-black">
                            تعذر تنفيذ العملية
                        </div>

                        <div class="mt-0.5 leading-5">
                            {{ $errorMessage }}
                        </div>

                    </div>

                    <button
                        type="button"
                        wire:click="$set('errorMessage', null)"
                        class="shrink-0 rounded-lg p-1 text-rose-500 transition hover:bg-rose-100"
                        aria-label="إغلاق"
                    >
                        ✕
                    </button>

                </div>

            @endif


            @if ($successMessage)

                <div
                    class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-3.5 text-xs text-emerald-800 shadow-sm"
                    role="alert"
                >

                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-100"
                    >
                        ✓
                    </div>

                    <div class="min-w-0 flex-1">

                        <div class="font-black">
                            تمت العملية بنجاح
                        </div>

                        <div class="mt-0.5 leading-5">
                            {{ $successMessage }}
                        </div>

                    </div>

                    <button
                        type="button"
                        wire:click="$set('successMessage', null)"
                        class="shrink-0 rounded-lg p-1 text-emerald-600 transition hover:bg-emerald-100"
                        aria-label="إغلاق"
                    >
                        ✕
                    </button>

                </div>

            @endif


            {{-- ========================================================= --}}
            {{-- Settled Banner --}}
            {{-- ========================================================= --}}

            @if ($is_settled)

                <div
                    class="overflow-hidden rounded-2xl bg-emerald-600 text-white shadow-sm"
                >

                    <div
                        class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >

                        <div class="flex items-start gap-3">

                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/15 text-lg"
                            >
                                ✓
                            </div>

                            <div>

                                <div class="text-xs font-black sm:text-sm">
                                    اليومية معتمدة ومغلقة
                                </div>

                                <div class="mt-0.5 text-[10px] text-emerald-100 sm:text-xs">
                                    تم اعتماد اليومية بتاريخ {{ $date }} ولا يمكن تعديلها.
                                </div>

                            </div>

                        </div>

                        <span
                            class="w-fit rounded-lg bg-emerald-700 px-3 py-1.5 text-[10px] font-black"
                        >
                            مقفلة
                        </span>

                    </div>

                </div>

            @endif


            {{-- ========================================================= --}}
            {{-- Summary Cards --}}
            {{-- ========================================================= --}}

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">

                {{-- Sales --}}

                <div
                    class="group rounded-2xl border border-emerald-100 bg-white p-4 shadow-sm transition hover:shadow-md"
                >

                    <div class="flex items-start justify-between">

                        <div>

                            <div class="text-[10px] font-bold text-slate-500">
                                إجمالي المبيعات
                            </div>

                            <div class="mt-2 text-xl font-black font-mono text-emerald-600">
                                {{ number_format($total_sales, 2) }}
                            </div>

                        </div>

                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-sm"
                        >
                            ↗
                        </div>

                    </div>

                    <div class="mt-3 h-1 overflow-hidden rounded-full bg-emerald-50">
                        <div class="h-full w-full rounded-full bg-emerald-500"></div>
                    </div>

                </div>


                {{-- Returns --}}

                <div
                    class="group rounded-2xl border border-rose-100 bg-white p-4 shadow-sm transition hover:shadow-md"
                >

                    <div class="flex items-start justify-between">

                        <div>

                            <div class="text-[10px] font-bold text-slate-500">
                                إجمالي المرتجعات
                            </div>

                            <div class="mt-2 text-xl font-black font-mono text-rose-600">
                                {{ number_format($total_returns, 2) }}
                            </div>

                        </div>

                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-sm"
                        >
                            ↙
                        </div>

                    </div>

                    <div class="mt-3 h-1 overflow-hidden rounded-full bg-rose-50">
                        <div class="h-full w-full rounded-full bg-rose-400"></div>
                    </div>

                </div>


                {{-- Expected Cash --}}

                <div
                    class="group rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4 shadow-sm transition hover:shadow-md"
                >

                    <div class="flex items-start justify-between">

                        <div>

                            <div class="text-[10px] font-bold text-indigo-700">
                                النقد المتوقع بالخزينة
                            </div>

                            <div class="mt-2 text-xl font-black font-mono text-indigo-700">
                                {{ number_format($expected_cash, 2) }}
                            </div>

                        </div>

                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-xl bg-white text-sm shadow-sm"
                        >
                            💰
                        </div>

                    </div>

                    <div class="mt-3 h-1 overflow-hidden rounded-full bg-indigo-100">
                        <div class="h-full w-full rounded-full bg-indigo-500"></div>
                    </div>

                </div>

            </div>


            {{-- ========================================================= --}}
            {{-- Settlement Card --}}
            {{-- ========================================================= --}}

            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >

                {{-- Card Header --}}

                <div
                    class="border-b border-slate-100 bg-slate-50/70 px-4 py-3 md:px-5"
                >

                    <div class="flex items-center justify-between gap-3">

                        <div>

                            <h2 class="text-sm font-black text-slate-800">
                                مطابقة الخزينة
                            </h2>

                            <p class="mt-0.5 text-[10px] text-slate-500">
                                أدخل النقد الفعلي ليتم احتساب العجز أو الزيادة.
                            </p>

                        </div>

                        @if ($closed_shifts_count > 0)

                            <span
                                class="shrink-0 rounded-lg bg-white px-2.5 py-1.5 text-[10px] font-bold text-slate-600 shadow-sm ring-1 ring-slate-200"
                            >
                                {{ $closed_shifts_count }} شيفت مغلق
                            </span>

                        @endif

                    </div>

                </div>


                {{-- Card Body --}}

                <div class="space-y-5 p-4 md:p-5">

                    {{-- Open Shifts Warning --}}

                    @if ($open_shifts_count > 0)

                        <div
                            class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3"
                        >

                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100"
                            >
                                ⚠️
                            </div>

                            <div>

                                <div class="text-xs font-black text-amber-900">
                                    لا يمكن اعتماد اليومية الآن
                                </div>

                                <div class="mt-0.5 text-[10px] leading-5 text-amber-800">
                                    يوجد {{ $open_shifts_count }} شيفت مفتوح.
                                    يجب إغلاق جميع الشيفتات أولًا.
                                </div>

                            </div>

                        </div>

                    @endif


                    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

                        {{-- Actual Cash --}}

                        <div>

                            <div class="mb-2 flex items-center justify-between">

                                <label
                                    class="text-xs font-black text-slate-700"
                                >
                                    النقد الفعلي المستلم
                                </label>

                                <span
                                    class="text-[10px] font-medium text-slate-400"
                                >
                                    المبلغ الموجود فعليًا بالخزينة
                                </span>

                            </div>

                            <div class="relative">

                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    wire:model.live.debounce.300ms="actual_cash"
                                    @disabled($is_settled)
                                    class="w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-4 pl-16 text-center text-2xl font-black font-mono text-slate-800 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:opacity-70"
                                    inputmode="decimal"
                                >

                                <span
                                    class="absolute left-4 top-1/2 -translate-y-1/2 rounded-lg bg-white px-2 py-1 text-[10px] font-bold text-slate-400 shadow-sm"
                                >
                                    CASH
                                </span>

                            </div>

                            <div class="mt-2 flex items-center justify-between text-[10px]">

                                <span class="text-slate-400">
                                    المتوقع:
                                </span>

                                <span class="font-bold font-mono text-indigo-600">
                                    {{ number_format($expected_cash, 2) }}
                                </span>

                            </div>

                        </div>


                        {{-- Difference --}}

                        <div>

                            <div class="mb-2 flex items-center justify-between">

                                <label
                                    class="text-xs font-black text-slate-700"
                                >
                                    نتيجة المطابقة
                                </label>

                                <span class="text-[10px] text-slate-400">
                                    فعلي − متوقع
                                </span>

                            </div>


                            @if ($difference == 0)

                                <div
                                    class="flex h-[74px] items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 px-4"
                                >

                                    <div class="flex items-center gap-3">

                                        <div
                                            class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700"
                                        >
                                            ✓
                                        </div>

                                        <div>

                                            <div class="text-xs font-black text-emerald-900">
                                                النقد مطابق
                                            </div>

                                            <div class="text-[10px] text-emerald-700">
                                                لا يوجد عجز أو زيادة.
                                            </div>

                                        </div>

                                    </div>

                                    <span class="font-black font-mono text-emerald-700">
                                        0.00
                                    </span>

                                </div>

                            @elseif ($difference < 0)

                                <div
                                    class="flex h-[74px] items-center justify-between rounded-2xl border border-rose-200 bg-rose-50 px-4"
                                >

                                    <div class="flex items-center gap-3">

                                        <div
                                            class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-100"
                                        >
                                            ⚠️
                                        </div>

                                        <div>

                                            <div class="text-xs font-black text-rose-900">
                                                عجز بالخزينة
                                            </div>

                                            <div class="text-[10px] text-rose-700">
                                                النقد الفعلي أقل من المتوقع.
                                            </div>

                                        </div>

                                    </div>

                                    <div class="text-left">

                                        <div class="text-[9px] text-rose-500">
                                            قيمة العجز
                                        </div>

                                        <div class="font-black font-mono text-rose-700">
                                            {{ number_format(abs($difference), 2) }}
                                        </div>

                                    </div>

                                </div>

                            @else

                                <div
                                    class="flex h-[74px] items-center justify-between rounded-2xl border border-amber-200 bg-amber-50 px-4"
                                >

                                    <div class="flex items-center gap-3">

                                        <div
                                            class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-100"
                                        >
                                            ↑
                                        </div>

                                        <div>

                                            <div class="text-xs font-black text-amber-900">
                                                زيادة بالخزينة
                                            </div>

                                            <div class="text-[10px] text-amber-700">
                                                النقد الفعلي أكبر من المتوقع.
                                            </div>

                                        </div>

                                    </div>

                                    <div class="text-left">

                                        <div class="text-[9px] text-amber-600">
                                            قيمة الزيادة
                                        </div>

                                        <div class="font-black font-mono text-amber-700">
                                            {{ number_format($difference, 2) }}
                                        </div>

                                    </div>

                                </div>

                            @endif

                        </div>

                    </div>


                    {{-- Notes --}}

                    <div>

                        <div class="mb-2 flex items-center justify-between">

                            <label
                                class="text-xs font-black text-slate-700"
                            >
                                ملاحظات اليومية
                            </label>

                            <span class="text-[10px] text-slate-400">
                                اختياري
                            </span>

                        </div>

                        <textarea
                            wire:model="notes"
                            rows="3"
                            @disabled($is_settled)
                            maxlength="2000"
                            placeholder="مثال: تم العثور على فرق بسيط وتمت مراجعته مع الكاشير..."
                            class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs leading-6 text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-50 disabled:cursor-not-allowed disabled:opacity-60"
                        ></textarea>

                    </div>

                </div>


                {{-- Footer --}}

                <div
                    class="flex flex-col gap-3 border-t border-slate-100 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between"
                >

                    <div class="text-[10px] leading-5 text-slate-500">

                        @if ($is_settled)

                            <span class="font-bold text-emerald-700">
                                ✓ تم اعتماد هذه اليومية ولا يمكن تعديلها.
                            </span>

                        @elseif ($open_shifts_count > 0)

                            <span class="font-bold text-amber-700">
                                أغلق الشيفتات المفتوحة أولًا.
                            </span>

                        @else

                            <span>
                                بعد الاعتماد سيتم ربط الشيفتات المغلقة بهذه اليومية.
                            </span>

                        @endif

                    </div>


                    <button
                        type="button"
                        wire:click="saveSettlement"
                        wire:loading.attr="disabled"
                        wire:target="saveSettlement"
                        @disabled($is_settled || $open_shifts_count > 0)
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-xs font-black text-white shadow-sm transition hover:bg-indigo-700 active:scale-[.98] disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 sm:w-auto"
                    >

                        <span
                            wire:loading.remove
                            wire:target="saveSettlement"
                        >
                            @if ($is_settled)
                                اليومية معتمدة ومغلقة
                            @else
                                اعتماد وتصفية اليومية
                            @endif
                        </span>

                        <span
                            wire:loading
                            wire:target="saveSettlement"
                            class="flex items-center gap-2"
                        >
                            <svg
                                class="h-4 w-4 animate-spin"
                                viewBox="0 0 24 24"
                                fill="none"
                            >
                                <circle
                                    cx="12"
                                    cy="12"
                                    r="9"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    class="opacity-30"
                                />

                                <path
                                    d="M21 12a9 9 0 0 0-9-9"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                />
                            </svg>

                            جاري الاعتماد...
                        </span>

                    </button>

                </div>

            </div>


            {{-- ========================================================= --}}
            {{-- Shifts Review --}}
            {{-- ========================================================= --}}

            <div
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >

                {{-- Header --}}

                <div
                    class="flex flex-col gap-2 border-b border-slate-100 bg-white p-4 sm:flex-row sm:items-center sm:justify-between"
                >

                    <div>

                        <div class="flex items-center gap-2">

                            <div
                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100"
                            >
                                🔄
                            </div>

                            <h2 class="text-sm font-black text-slate-800">
                                تفاصيل شيفتات اليوم
                            </h2>

                            <span
                                class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-black text-slate-600"
                            >
                                {{ count($dayShifts) }}
                            </span>

                        </div>

                        <p class="mt-1 text-[10px] text-slate-400">
                            مراجعة حالة كل شيفت قبل اعتماد اليومية.
                        </p>

                    </div>


                    {{-- Status summary --}}

                    <div class="flex items-center gap-2">

                        @if ($open_shifts_count > 0)

                            <span
                                class="rounded-lg bg-amber-50 px-2.5 py-1.5 text-[10px] font-black text-amber-700 ring-1 ring-amber-100"
                            >
                                {{ $open_shifts_count }} مفتوح
                            </span>

                        @endif

                        <span
                            class="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-[10px] font-black text-emerald-700 ring-1 ring-emerald-100"
                        >
                            {{ $closed_shifts_count }} مغلق
                        </span>

                    </div>

                </div>


                {{-- Table --}}

                <div class="overflow-x-auto">

                    <table class="w-full min-w-[1000px] text-right text-xs">

                        <thead>

                            <tr
                                class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 font-black text-slate-500"
                            >

                                <th class="rounded-r-lg p-3">
                                    الشيفت
                                </th>

                                <th class="p-3">
                                    الموظف / الكاشير
                                </th>

                                <th class="p-3">
                                    الفتح
                                </th>

                                <th class="p-3">
                                    الإغلاق
                                </th>

                                <th class="p-3 text-center">
                                    الحالة
                                </th>

                                <th class="p-3">
                                    الافتتاحي
                                </th>

                                <th class="p-3">
                                    المبيعات
                                </th>

                                <th class="p-3">
                                    المرتجعات
                                </th>

                                <th class="p-3">
                                    الفرق
                                </th>

                                <th class="rounded-l-lg p-3">
                                    النقد الفعلي
                                </th>

                            </tr>

                        </thead>


                        <tbody class="divide-y divide-slate-100">

                            @forelse ($dayShifts as $shift)

                                @php
                                    $shiftDifference = round(
                                        (float) ($shift->difference ?? 0),
                                        2
                                    );
                                @endphp

                                <tr
                                    wire:key="daily-settlement-shift-{{ $shift->id }}"
                                    class="group transition-colors hover:bg-slate-50"
                                >

                                    {{-- Shift --}}

                                    <td class="p-3">

                                        <span
                                            class="rounded-lg bg-slate-100 px-2 py-1 font-bold font-mono text-slate-700 group-hover:bg-white"
                                        >
                                            #{{ $shift->id }}
                                        </span>

                                    </td>


                                    {{-- User --}}

                                    <td class="p-3">

                                        <div class="font-bold text-slate-800">
                                            {{ $shift->user?->name ?? 'غير محدد' }}
                                        </div>

                                    </td>


                                    {{-- Open --}}

                                    <td class="p-3 font-mono text-slate-500">

                                        @if ($shift->opened_at)
                                            {{ \Carbon\Carbon::parse($shift->opened_at)->format('H:i') }}
                                        @else
                                            —
                                        @endif

                                    </td>


                                    {{-- Close --}}

                                    <td class="p-3 font-mono text-slate-500">

                                        @if ($shift->closed_at)
                                            {{ \Carbon\Carbon::parse($shift->closed_at)->format('H:i') }}
                                        @else
                                            —
                                        @endif

                                    </td>


                                    {{-- Status --}}

                                    <td class="p-3 text-center">

                                        @if ($shift->status === 'open')

                                            <span
                                                class="inline-flex items-center gap-1 rounded-lg bg-amber-100 px-2.5 py-1 text-[10px] font-black text-amber-800"
                                            >
                                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                                مفتوح
                                            </span>

                                        @elseif ($shift->status === 'closed')

                                            <span
                                                class="inline-flex items-center gap-1 rounded-lg bg-emerald-100 px-2.5 py-1 text-[10px] font-black text-emerald-800"
                                            >
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                مغلق
                                            </span>

                                        @else

                                            <span
                                                class="rounded-lg bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600"
                                            >
                                                {{ $shift->status ?? 'غير معروف' }}
                                            </span>

                                        @endif

                                    </td>


                                    {{-- Opening Cash --}}

                                    <td class="p-3 font-mono text-slate-600">
                                        {{ number_format((float) ($shift->opening_cash ?? 0), 2) }}
                                    </td>


                                    {{-- Sales --}}

                                    <td class="p-3 font-bold font-mono text-emerald-700">
                                        {{ number_format((float) ($shift->total_sales ?? 0), 2) }}
                                    </td>


                                    {{-- Returns --}}

                                    <td class="p-3 font-bold font-mono text-rose-600">
                                        {{ number_format((float) ($shift->total_returns ?? 0), 2) }}
                                    </td>


                                    {{-- Difference --}}

                                    <td class="p-3">

                                        @if ($shiftDifference < 0)

                                            <span
                                                class="font-bold font-mono text-rose-600"
                                            >
                                                {{ number_format($shiftDifference, 2) }}
                                            </span>

                                        @elseif ($shiftDifference > 0)

                                            <span
                                                class="font-bold font-mono text-amber-600"
                                            >
                                                +{{ number_format($shiftDifference, 2) }}
                                            </span>

                                        @else

                                            <span
                                                class="font-bold font-mono text-slate-400"
                                            >
                                                0.00
                                            </span>

                                        @endif

                                    </td>


                                    {{-- Actual Cash --}}

                                    <td class="p-3">

                                        <span
                                            class="font-black font-mono text-indigo-700"
                                        >
                                            {{ number_format((float) ($shift->actual_cash ?? 0), 2) }}
                                        </span>

                                    </td>

                                </tr>

                            @empty

                                <tr>

                                    <td
                                        colspan="10"
                                        class="p-12 text-center"
                                    >

                                        <div
                                            class="mx-auto flex max-w-xs flex-col items-center"
                                        >

                                            <div
                                                class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-2xl"
                                            >
                                                📭
                                            </div>

                                            <div
                                                class="mt-3 text-xs font-black text-slate-600"
                                            >
                                                لا توجد شيفتات لهذا التاريخ
                                            </div>

                                            <div
                                                class="mt-1 text-[10px] leading-5 text-slate-400"
                                            >
                                                لم يتم تسجيل أي شيفت بتاريخ
                                                {{ $date }}.
                                            </div>

                                        </div>

                                    </td>

                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

            </div>


            {{-- ========================================================= --}}
            {{-- Final Financial Snapshot --}}
            {{-- ========================================================= --}}

            @if ($is_settled)

                <div>

                    <div class="mb-3 px-1">

                        <h3 class="text-xs font-black text-slate-700">
                            ملخص التسوية المعتمدة
                        </h3>

                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">

                        {{-- Expected --}}

                        <div
                            class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4"
                        >

                            <div class="text-[10px] font-bold text-indigo-600">
                                النقد المتوقع
                            </div>

                            <div class="mt-1 text-lg font-black font-mono text-indigo-900">
                                {{ number_format($expected_cash, 2) }}
                            </div>

                        </div>


                        {{-- Actual --}}

                        <div
                            class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4"
                        >

                            <div class="text-[10px] font-bold text-emerald-600">
                                النقد الفعلي
                            </div>

                            <div class="mt-1 text-lg font-black font-mono text-emerald-900">
                                {{ number_format($actual_cash, 2) }}
                            </div>

                        </div>


                        {{-- Difference --}}

                        <div
                            class="
                                rounded-2xl border p-4
                                @if ($difference < 0)
                                    border-rose-100 bg-rose-50
                                @elseif ($difference > 0)
                                    border-amber-100 bg-amber-50
                                @else
                                    border-slate-200 bg-slate-50
                                @endif
                            "
                        >

                            <div
                                class="
                                    text-[10px] font-bold
                                    @if ($difference < 0)
                                        text-rose-600
                                    @elseif ($difference > 0)
                                        text-amber-600
                                    @else
                                        text-slate-600
                                    @endif
                                "
                            >
                                الفرق النهائي
                            </div>

                            <div
                                class="
                                    mt-1 text-lg font-black font-mono
                                    @if ($difference < 0)
                                        text-rose-900
                                    @elseif ($difference > 0)
                                        text-amber-900
                                    @else
                                        text-slate-900
                                    @endif
                                "
                            >
                                @if ($difference > 0)
                                    +{{ number_format($difference, 2) }}
                                @else
                                    {{ number_format($difference, 2) }}
                                @endif
                            </div>

                        </div>

                    </div>

                </div>

            @endif

        </div>

    </div>

</flux:main>
