<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $branchId = $request->get('branch_id') ?? $user->branch_id;
        abort_if(! $branchId, 422, 'حدد الفرع لعرض الوردية.');
        $tenantId = $user->tenant_id;

        $shift = Shift::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        return response()->json([
            'active_shift' => $shift,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;

        $existing = Shift::where('tenant_id', $tenantId)
            ->where('branch_id', $validated['branch_id'])
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'يوجد بالفعل وردية مفتوحة لهذا الفرع',
                'shift' => $existing,
            ], 400);
        }

        $shift = Shift::create([
            'tenant_id' => $tenantId,
            'branch_id' => $validated['branch_id'],
            'opened_by' => $user->id,
            'opening_cash' => $validated['opening_cash'],
            'status' => 'open',
            'opened_at' => now(),
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'تم فتح الوردية بنجاح',
            'shift' => $shift,
        ], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_id' => ['required', 'exists:shifts,id'],
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user() ?? auth()->user();
        $shift = Shift::query()->where('tenant_id', $user->tenant_id)
            ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->whereKey((int) $validated['shift_id'])->firstOrFail();

        if ($shift->status !== 'open') {
            return response()->json([
                'message' => 'هذه الوردية مغلقة بالفعل',
            ], 400);
        }

        $totalSales = $shift->orders()->sum('total');
        $expectedCash = $shift->opening_cash + $totalSales;
        $actualCash = $validated['actual_cash'];
        $difference = $actualCash - $expectedCash;

        $shift->update([
            'total_sales' => $totalSales,
            'expected_cash' => $expectedCash,
            'actual_cash' => $actualCash,
            'difference' => $difference,
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $user->id,
            'notes' => $validated['notes'] ?? $shift->notes,
        ]);

        return response()->json([
            'message' => 'تم إغلاق الوردية بنجاح',
            'shift' => $shift,
        ]);
    }
}
