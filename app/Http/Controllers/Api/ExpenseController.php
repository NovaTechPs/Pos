<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $branchId = $request->get('branch_id') ?? $user->branch_id;
        abort_if(! $branchId, 422, 'حدد الفرع لعرض المصروفات.');

        $expenses = Payment::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('type', 'payment')
            ->latest()
            ->get();

        return response()->json([
            'expenses' => $expenses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $branchId = $validated['branch_id'];

        $activeShift = Shift::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        $payment = Payment::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'created_by' => $user->id,
            'shift_id' => $activeShift?->id,
            'type' => 'payment',
            'voucher_number' => 'EXP-'.time().'-'.rand(100, 999),
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'] ?? 'cash',
            'payment_date' => now(),
            'notes' => $validated['notes'] ?? 'مصروفات نقدية من الصندوق',
        ]);

        return response()->json([
            'message' => 'تم تسجيل المصروف بنجاح',
            'expense' => $payment,
        ], 201);
    }
}
