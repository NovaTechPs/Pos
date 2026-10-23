<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestedType = $request->string('type')->toString();
        $allowed = match ($requestedType) {
            'receipt' => $user->hasPermission('accounting.receipt.view'),
            'payment' => $user->hasPermission('accounting.voucher.view') || $user->hasPermission('accounting.payments.view'),
            default => $user->hasPermission('accounting.receipt.view') || $user->hasPermission('accounting.voucher.view') || $user->hasPermission('accounting.payments.view'),
        };
        abort_unless($allowed, 403);
        $vouchers = Payment::query()->with('party')->where('tenant_id', $user->tenant_id)
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('payment_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('payment_date', '<=', $request->date('to')))
            ->latest('payment_date')->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json(['vouchers' => $vouchers]);
    }

    public function storeReceipt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'], 'customer_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'], 'payment_method' => ['nullable', 'in:cash,card,bank_transfer,cheque'],
            'payment_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $user = $request->user();
        $branchId = (int) $validated['branch_id'];
        $activeShift = Shift::query()->where('tenant_id', $user->tenant_id)->where('branch_id', $branchId)
            ->where('status', 'open')->latest('opened_at')->first();

        $payment = DB::transaction(function () use ($validated, $user, $branchId, $activeShift): Payment {
            $customer = ! empty($validated['customer_id'])
                ? Party::query()->where('tenant_id', $user->tenant_id)->whereIn('type', ['customer', 'both'])
                    ->where('is_active', true)->lockForUpdate()->findOrFail((int) $validated['customer_id'])
                : null;
            $payment = Payment::create([
                'tenant_id' => $user->tenant_id, 'branch_id' => $branchId, 'created_by' => $user->id,
                'party_id' => $customer?->id, 'shift_id' => $activeShift?->id, 'type' => 'receipt',
                'voucher_number' => 'RCP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
                'amount' => $validated['amount'], 'payment_method' => $validated['payment_method'] ?? 'cash',
                'payment_date' => $validated['payment_date'] ?? now(), 'notes' => $validated['notes'] ?? 'سند قبض نقدي',
            ]);
            if ($customer) {
                $customer->decrement('current_balance', (float) $validated['amount']);
            }

            return $payment;
        });

        return response()->json(['message' => 'تم إصدار سند القبض بنجاح', 'voucher' => $payment], 201);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'], 'supplier_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'], 'payment_method' => ['required', 'in:cash,card,bank_transfer,cheque'],
            'payment_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $user = $request->user();
        $branchId = (int) $validated['branch_id'];
        $payment = DB::transaction(function () use ($validated, $user, $branchId): Payment {
            $supplier = ! empty($validated['supplier_id'])
                ? Party::query()->where('tenant_id', $user->tenant_id)->whereIn('type', ['supplier', 'both'])
                    ->where('is_active', true)->lockForUpdate()->findOrFail((int) $validated['supplier_id'])
                : null;
            $payment = Payment::create([
                'tenant_id' => $user->tenant_id, 'branch_id' => $branchId, 'created_by' => $user->id,
                'party_id' => $supplier?->id, 'type' => 'payment',
                'voucher_number' => 'PAY-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
                'amount' => $validated['amount'], 'payment_method' => $validated['payment_method'],
                'payment_date' => $validated['payment_date'] ?? now(), 'notes' => $validated['notes'] ?? null,
            ]);
            if ($supplier) {
                $supplier->decrement('current_balance', (float) $validated['amount']);
            }

            return $payment;
        });

        return response()->json(['message' => 'تم إصدار سند الصرف بنجاح', 'voucher' => $payment], 201);
    }
}
