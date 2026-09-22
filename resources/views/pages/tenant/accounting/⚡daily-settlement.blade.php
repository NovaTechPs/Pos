<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Shift;
use App\Models\Order;
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
    public int $closed_shifts_count = 0;
    public bool $is_settled = false;
    public ?DailySettlement $existingSettlement = null;

    // قائمة شيفتات اليوم للمراجعة
    public $dayShifts = [];

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

        // 1. جلب كافة شيفتات اليوم للمراجعة
        $this->dayShifts = Shift::with('user')
            ->where('tenant_id', $tenantId)
            ->whereDate('opened_at', $this->date)
            ->latest('opened_at')
            ->get();

        // 2. التحقق من وجود تسوية معتمدة لهذا اليوم
        $this->existingSettlement = DailySettlement::where('tenant_id', $tenantId)
            ->where('settlement_date', $this->date)
            ->first();

        if ($this->existingSettlement) {
            $this->is_settled = true;
            $this->total_sales = (float) $this->existingSettlement->total_sales;
            $this->total_returns = (float) $this->existingSettlement->total_returns;
            $this->expected_cash = (float) $this->existingSettlement->total_cash;
            $this->actual_cash = (float) $this->existingSettlement->total_cash;
            $this->difference = 0.0;
            $this->notes = $this->existingSettlement->notes ?? '';
            $this->open_shifts_count = 0;
            return;
        }

        $this->is_settled = false;

        // 3. فحص الشيفتات المفتوحة
        $this->open_shifts_count = Shift::where('tenant_id', $tenantId)
            ->whereDate('opened_at', $this->date)
            ->where('status', 'open')
            ->count();

        // 4. تجميع الشيفتات المغلقة
        $closedShifts = $this->dayShifts->where('status', 'closed');
        $closedShiftIds = $closedShifts->pluck('id')->toArray();

        $this->closed_shifts_count = $closedShifts->count();

        // جلب إجمالي المبيعات والمرتجعات من جدول الفواتير مباشرة لتفادي القيمة 0
        if (!empty($closedShiftIds)) {
            $this->total_sales = (float) Order::whereIn('shift_id', $closedShiftIds)
                ->where('type', 'sale') // تعديل اسم العمود/النوع حسب المتبع لديك
                ->sum('total_amount');

            $this->total_returns = (float) Order::whereIn('shift_id', $closedShiftIds)
                ->where('type', 'return') // تعديل اسم العمود/النوع حسب المتبع لديك
                ->sum('total_amount');
        } else {
            $this->total_sales = (float) $closedShifts->sum('total_sales');
            $this->total_returns = (float) $closedShifts->sum('total_returns');
        }

        // النقد المتوقع المجمع من الشيفتات المقفلة
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
            $this->errorMessage = "تعذر اعتماد اليومية! هناك {$this->open_shifts_count} شيفت مفتوح، يرجى إغلاقه أولاً من شاشة الـ POS.";
            return;
        }

        $user = Auth::user();
        $userId = $user ? $user->id : auth()->id();
        $tenantId = session('active_tenant_id') ?? $user?->tenant_id ?? 1;
        $branchId = session('active_branch_id') ?? $user?->branch_id ?? 1;

        try {
            DB::transaction(function () use ($tenantId, $branchId, $userId) {
                // إنشاء سجل التسوية اليومية
                $this->existingSettlement = DailySettlement::create([
                    'tenant_id'          => $tenantId,
                    'branch_id'          => $branchId,
                    'closed_by'          => $userId,
                    'settlement_date'    => $this->date,
                    'total_sales'        => $this->total_sales,
                    'total_returns'      => $this->total_returns,
                    'total_cash'         => (float) $this->actual_cash,
                    'total_card'         => 0.00,
                    'total_shifts_count' => $this->closed_shifts_count,
                    'closed_at'          => now(),
                    'notes'              => $this->notes,
                ]);

                // ربط جميع شيفتات اليوم المغلقة برقم التسوية اليومية
                Shift::where('tenant_id', $tenantId)
                    ->whereDate('opened_at', $this->date)
                    ->where('status', 'closed')
                    ->update([
                        'daily_settlement_id' => $this->existingSettlement->id,
                    ]);

                $this->is_settled = true;
            });

            $this->successMessage = 'تم اعتماد اليومية وحفظ التسوية المالية وربط الشيفتات بنجاح!';
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

        <!-- Card الاعتماد والمدخلات -->
        <div class="bg-white border border-slate-300 rounded-2xl shadow-sm overflow-hidden divide-y divide-slate-100">

            <!-- ملخص المبيعات -->
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

            <!-- المطابقة والملاحظات -->
            <div class="p-5 space-y-4">
                @if($open_shifts_count > 0)
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-xs font-bold flex items-center gap-2">
                        <span>⚠️</span>
                        <span>يوجد عدد ({{ $open_shifts_count }}) شيفت مفتوح حالياً، يجب إغلاق الشيفتات أولاً للتمكن من اعتماد اليومية.</span>
                    </div>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ الفعلي المقبوض للترزينة (الدرج الرئيسي):</label>
                        <input type="number" step="0.01" wire:model.live.debounce.300ms="actual_cash"
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

            <!-- زر الاعتماد -->
            <div class="p-4 bg-slate-50 flex justify-end">
                <button wire:click="saveSettlement"
                    @if($is_settled || $open_shifts_count > 0) disabled @endif
                    class="w-full sm:w-auto px-6 py-3 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 disabled:text-slate-500 text-white rounded-xl font-extrabold text-xs shadow active:scale-95 transition-all">
                    {{ $is_settled ? 'اليومية معتمدة ومغلقة' : 'اعتماد وتصفية اليومية المالية' }}
                </button>
            </div>

        </div>

        <!-- جدول مراجعة ورديات الشيفتات -->
        <div class="bg-white border border-slate-300 rounded-2xl shadow-sm overflow-hidden p-5 space-y-3">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                    <span>🔄</span>
                    <span>تفاصيل شيفتات اليوم ({{ count($dayShifts) }})</span>
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead>
                        <tr class="bg-slate-100 text-slate-600 font-extrabold border-b border-slate-200">
                            <th class="p-2.5 rounded-r-lg"># رقم الشيفت</th>
                            <th class="p-2.5">الموظف / الكاشير</th>
                            <th class="p-2.5">وقت الفتح</th>
                            <th class="p-2.5">وقت الإغلاق</th>
                            <th class="p-2.5 text-center">الحالة</th>
                            <th class="p-2.5">الافتتاحي</th>
                            <th class="p-2.5">المبيعات</th>
                            <th class="p-2.5">المرتجعات</th>
                            <th class="p-2.5">الفارق</th>
                            <th class="p-2.5 rounded-l-lg">النقد الفعلي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                        @forelse($dayShifts as $shift)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="p-2.5 font-mono font-bold">#{{ $shift->id }}</td>
                                <td class="p-2.5 font-bold text-slate-800">{{ $shift->user->name ?? 'غير محدد' }}</td>
                                <td class="p-2.5 font-mono text-slate-500">{{ \Carbon\Carbon::parse($shift->opened_at)->format('H:i') }}</td>
                                <td class="p-2.5 font-mono text-slate-500">
                                    {{ $shift->closed_at ? \Carbon\Carbon::parse($shift->closed_at)->format('H:i') : '—' }}
                                </td>
                                <td class="p-2.5 text-center">
                                    @if($shift->status === 'open')
                                        <span class="px-2 py-0.5 text-[10px] bg-amber-100 text-amber-800 rounded-md font-bold">مفتوح</span>
                                    @else
                                        <span class="px-2 py-0.5 text-[10px] bg-emerald-100 text-emerald-800 rounded-md font-bold">مغلق</span>
                                    @endif
                                </td>
                                <td class="p-2.5 font-mono text-slate-600">{{ number_format($shift->opening_cash ?? 0, 2) }}</td>
                                <td class="p-2.5 font-mono text-emerald-700 font-bold">{{ number_format($shift->total_sales ?? 0, 2) }}</td>
                                <td class="p-2.5 font-mono text-rose-600 font-bold">{{ number_format($shift->total_returns ?? 0, 2) }}</td>
                                <td class="p-2.5 font-mono font-bold {{ $shift->difference < 0 ? 'text-rose-600' : ($shift->difference > 0 ? 'text-amber-600' : 'text-slate-500') }}">
                                    {{ number_format($shift->difference ?? 0, 2) }}
                                </td>
                                <td class="p-2.5 font-mono text-indigo-700 font-black">{{ number_format($shift->actual_cash ?? 0, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="p-4 text-center text-slate-400 font-bold">
                                    لا يوجد أي شيفتات مسجلة لهذا التاريخ.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
</flux:main>
