<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Shift;
use App\Models\DailySettlement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {
    // --- البيانات والحالة (State) ---
    public string $date = '';
    public float $total_sales = 0.0;
    public float $total_returns = 0.0;
    public float $expected_cash = 0.0;
    public float $actual_cash = 0.0;
    public float $difference = 0.0;
    public string $notes = '';
    public int $open_shifts_count = 0;
    public bool $is_settled = false;
    public ?DailySettlement $existingSettlement = null;

    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    public function mount()
    {
        $this->date = now()->format('Y-m-d');
        $this->loadData();
    }

    public function updatedDate()
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $tenantId = session('active_tenant_id') ?? Auth::user()->tenant_id;

        // 1. التحقق من وجود تسوية معتمدة لهذا اليوم
        $this->existingSettlement = DailySettlement::where('tenant_id', $tenantId)
            ->where('settlement_date', $this->date)
            ->first();

        if ($this->existingSettlement) {
            $this->is_settled = true;
            $this->total_sales = (float) $this->existingSettlement->total_sales;
            $this->total_returns = (float) $this->existingSettlement->total_returns;
            $this->expected_cash = (float) $this->existingSettlement->expected_cash;
            $this->actual_cash = (float) $this->existingSettlement->actual_cash;
            $this->difference = (float) $this->existingSettlement->difference;
            $this->notes = $this->existingSettlement->notes ?? '';
            $this->open_shifts_count = 0;
            return;
        }

        $this->is_settled = false;

        // 2. فحص الشيفتات المفتوحة اليوم ولديها مبيعات فعليّة
        $this->open_shifts_count = Shift::where('tenant_id', $tenantId)
            ->whereDate('opened_at', $this->date)
            ->where('status', 'open')
            ->whereHas('orders')
            ->count();

        // 3. تجميع كافة الشيفتات المغلقة لهذا اليوم
        $closedShifts = Shift::where('tenant_id', $tenantId)
            ->whereDate('opened_at', $this->date)
            ->where('status', 'closed')
            ->get();

        $this->total_sales = (float) $closedShifts->sum('total_sales');
        $this->total_returns = (float) $closedShifts->sum('total_returns');

        // النقد المتوقع بالخزينة الرئيسية هو مجموع المبالغ المقبوضة فعلياً والمرحلة من الشيفتات
        $this->expected_cash = (float) $closedShifts->sum('actual_cash');

        if ($this->actual_cash === 0.0 || !$this->is_settled) {
            $this->actual_cash = $this->expected_cash;
        }

        $this->calculateDifference();
    }

    public function updatedActualCash()
    {
        $this->calculateDifference();
    }

    public function calculateDifference(): void
    {
        $this->difference = (float) $this->actual_cash - (float) $this->expected_cash;
    }

    public function saveSettlement(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if ($this->open_shifts_count > 0) {
            $this->errorMessage = "تعذر اعتماد اليومية! هناك {$this->open_shifts_count} شيفت مفتوح يحتوي على مبيعات، يرجى إغلاقه أولاً من شاشة الـ POS.";
            return;
        }

        $tenantId = session('active_tenant_id') ?? Auth::user()->tenant_id;
        $user = Auth::user();

        try {
            DB::transaction(function () use ($tenantId, $user) {
                $this->existingSettlement = DailySettlement::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'settlement_date' => $this->date,
                    'total_sales' => $this->total_sales,
                    'total_returns' => $this->total_returns,
                    'expected_cash' => $this->expected_cash,
                    'actual_cash' => (float) $this->actual_cash,
                    'difference' => $this->difference,
                    'notes' => $this->notes,
                    'status' => 'completed',
                ]);

                $this->is_settled = true;
            });

            $this->successMessage = 'تم اعتماد اليومية وحفظ التسوية المالية بنجاح!';
        } catch (\Exception $e) {
            $this->errorMessage = 'حدث خطأ أثناء إغلاق اليومية: ' . $e->getMessage();
        }
    }

    public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};
?>
<flux:main class="space-y-6">


<div class="p-4 md:p-6 bg-slate-100 min-h-screen font-sans select-none">
    <div class="max-w-4xl mx-auto space-y-4">

        <!-- Header -->
        <div class="bg-white border border-slate-300 rounded-2xl p-4 shadow-sm flex flex-col sm:flex-row justify-between items-center gap-4">
            <div>
                <h1 class="text-lg font-black text-slate-800 flex items-center gap-2">
                    <span>📑</span>
                    <span>التسوية وإغلاق اليومية المالية</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">مطابقة واستلام النقد المجمع من كافة الورديات في الخزينة الرئيسية</p>
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto">
                <label class="text-xs font-bold text-slate-700 whitespace-nowrap">تاريخ اليومية:</label>
                <input type="date" wire:model.live="date"
                    class="bg-slate-50 border border-slate-300 rounded-xl px-3 py-1.5 text-xs font-bold font-mono text-slate-800 focus:outline-indigo-600">
            </div>
        </div>

        <!-- التنبيهات -->
        @if ($errorMessage)
            <div class="bg-rose-50 border border-rose-200 text-rose-700 p-3 rounded-xl text-xs font-semibold flex items-center justify-between shadow-sm">
                <span>{{ $errorMessage }}</span>
                <button wire:click="$set('errorMessage', null)" class="text-rose-500 font-bold text-sm">✕</button>
            </div>
        @endif

        @if ($successMessage)
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-3 rounded-xl text-xs font-semibold flex items-center justify-between shadow-sm">
                <span>{{ $successMessage }}</span>
                <button wire:click="$set('successMessage', null)" class="text-emerald-600 font-bold text-sm">✕</button>
            </div>
        @endif

        @if ($is_settled)
            <div class="bg-emerald-500 text-white p-3 rounded-xl text-xs font-bold flex items-center justify-between shadow-sm">
                <span class="flex items-center gap-1.5">
                    <span>✅</span>
                    <span>تم إغلاق واعتماد اليومية المالية لهذا التاريخ ({{ $date }}) بنجاح.</span>
                </span>
                <span class="bg-emerald-700 text-white text-[10px] px-2 py-1 rounded font-mono">مقفلة</span>
            </div>
        @endif

        <!-- Card المحتوى -->
        <div class="bg-white border border-slate-300 rounded-2xl shadow-sm overflow-hidden divide-y divide-slate-100">

            <!-- ملخص المبيعات والشيفتات -->
            <div class="p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <span class="text-xs font-bold text-slate-500 block mb-1">إجمالي مبيعات الشيفتات:</span>
                    <span class="text-xl font-black font-mono text-emerald-600">{{ number_format($total_sales, 2) }}</span>
                </div>

                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <span class="text-xs font-bold text-slate-500 block mb-1">إجمالي المرتجعات:</span>
                    <span class="text-xl font-black font-mono text-rose-600">{{ number_format($total_returns, 2) }}</span>
                </div>

                <div class="bg-indigo-50/60 p-4 rounded-xl border border-indigo-100">
                    <span class="text-xs font-bold text-indigo-900 block mb-1">المستلم المتوقع بالخزينة:</span>
                    <span class="text-xl font-black font-mono text-indigo-700">{{ number_format($expected_cash, 2) }}</span>
                </div>
            </div>

            <!-- إدخال الفعلي والمطابقة -->
            <div class="p-5 space-y-4">
                @if($open_shifts_count > 0)
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-xs font-bold flex items-center gap-2">
                        <span>⚠️</span>
                        <span>يوجد عدد ({{ $open_shifts_count }}) شيفت مفتوح حالياً وبها حركات بيع، يجب إغلاقها للتمكن من اعتماد اليومية.</span>
                    </div>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ الفعلي المقبوض للترزينة (الدرج الرئيسي):</label>
                        <input type="number" step="0.01" wire:model.live="actual_cash"
                            @if($is_settled) disabled @endif
                            class="w-full bg-slate-50 border border-slate-300 rounded-xl p-3 text-lg font-black font-mono text-center focus:outline-indigo-600 disabled:opacity-70">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">النتيجة والمطابقة:</label>
                        <div class="p-3 rounded-xl text-center font-bold text-xs border {{ $difference == 0 ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ($difference < 0 ? 'bg-rose-50 text-rose-800 border-rose-200' : 'bg-amber-50 text-amber-800 border-amber-200') }}">
                            @if($difference == 0)
                                النقد مطابق للمقبوض تماماً 👌
                            @elseif($difference < 0)
                                عجز بالخزينة بمقدار: <span class="font-mono text-sm font-black mr-1">{{ number_format(abs($difference), 2) }}</span> ⚠️
                            @else
                                زيادة بالخزينة بمقدار: <span class="font-mono text-sm font-black mr-1">{{ number_format($difference, 2) }}</span> 💡
                            @endif
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات اعتماد اليومية:</label>
                    <textarea wire:model="notes" rows="2"
                        @if($is_settled) disabled @endif
                        placeholder="أدخل أي ملاحظات حول اليومية هنا..."
                        class="w-full bg-slate-50 border border-slate-300 rounded-xl p-2.5 text-xs focus:outline-indigo-600 disabled:opacity-70"></textarea>
                </div>
            </div>

            <!-- الزر والاعتماد -->
            <div class="p-4 bg-slate-50 flex justify-end">
                <button wire:click="saveSettlement"
                    @if($is_settled || $open_shifts_count > 0) disabled @endif
                    class="w-full sm:w-auto px-6 py-3 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 disabled:text-slate-500 text-white rounded-xl font-extrabold text-xs shadow active:scale-95 transition-all">
                    {{ $is_settled ? 'اليومية معتمدة ومغلقة' : 'اعتماد وتصفية اليومية المالية' }}
                </button>
            </div>

        </div>
    </div>
</div>
</flux:main>
