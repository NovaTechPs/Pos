<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Shift;
use App\Services\ApiShiftLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $request->integer('branch_id') ?: $user->branch_id;
        abort_if(! $branchId, 422, 'حدد الفرع لعرض الوردية.');
        $shift = Shift::query()->where('tenant_id', $user->tenant_id)->where('branch_id', $branchId)
            ->where('opened_by', $user->id)->where('status', 'open')->latest('opened_at')->first();
        if ($shift) {
            $shift->setAttribute('cash_summary', ApiShiftLedger::summary($shift));
        }

        return response()->json(['active_shift' => $shift]);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $user = $request->user();
        $shift = DB::transaction(function () use ($validated, $user): Shift {
            $existing = ApiShiftLedger::activeForWrite($user->tenant_id, (int) $validated['branch_id'], $user->id);
            abort_if($existing !== null, 409, 'يوجد بالفعل وردية مفتوحة لك في هذا الفرع.');

            return Shift::create([
                'tenant_id' => $user->tenant_id, 'branch_id' => $validated['branch_id'], 'opened_by' => $user->id,
                'opening_cash' => $validated['opening_cash'], 'status' => 'open', 'opened_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json(['message' => 'تم فتح الوردية بنجاح', 'shift' => $shift], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_id' => ['required', 'integer'],
            'actual_cash' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'expected_cash' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $user = $request->user();
        $candidate = Shift::query()->where('tenant_id', $user->tenant_id)
            ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->whereKey((int) $validated['shift_id'])->firstOrFail();
        $shift = DB::transaction(function () use ($candidate, $validated, $user): Shift {
            Branch::query()->where('tenant_id', $user->tenant_id)->whereKey($candidate->branch_id)->lockForUpdate()->firstOrFail();
            $shift = Shift::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless($shift->status === 'open', 409, 'هذه الوردية مغلقة بالفعل.');
            $summary = ApiShiftLedger::summary($shift);
            abort_if(isset($validated['expected_cash']) && abs((float) $validated['expected_cash'] - $summary['expected_cash']) > 0.005,
                409, 'حدثت حركة جديدة أثناء التسوية. افتح نافذة الإغلاق مجدداً وأعد مراجعة الصندوق.');
            $shift->update([
                'total_sales' => $summary['sales'], 'total_returns' => $summary['returns'],
                'expected_cash' => $summary['expected_cash'], 'actual_cash' => $validated['actual_cash'],
                'difference' => round((float) $validated['actual_cash'] - $summary['expected_cash'], 2),
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->id,
                'notes' => $validated['notes'] ?? $shift->notes,
            ]);

            return $shift;
        });

        return response()->json(['message' => 'تم إغلاق الوردية بنجاح', 'shift' => $shift]);
    }
}
