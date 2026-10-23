<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartyStatementController extends Controller
{
    public function show(Request $request, Party $party): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $hasStatementPermission = $party->type === 'supplier'
            ? $user->hasPermission('suppliers.statement')
            : ($party->type === 'both'
                ? $user->hasPermission('customers.statement') || $user->hasPermission('suppliers.statement')
                : $user->hasPermission('customers.statement'));
        abort_unless($hasStatementPermission, 403);
        abort_unless((int) $party->tenant_id === (int) $user->tenant_id, 404);
        abort_unless(! $user->branch_id || $user->isTenantOwner() || ! $party->branch_id || (int) $party->branch_id === (int) $user->branch_id, 404);
        $from = $request->date('from');
        $to = $request->date('to');
        $branchId = $user->branch_id && ! $user->isTenantOwner() ? $user->branch_id : $request->integer('branch_id');
        $orders = $party->orders()->where('tenant_id', $user->tenant_id)->where('status', 'completed')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))->get()->map(fn ($r) => ['id' => $r->id, 'date' => $r->created_at->toDateString(), 'type' => $r->type === 'return' ? 'مرتجع مبيعات' : 'فاتورة بيع', 'amount' => $r->total, 'notes' => $r->invoice_number]);
        $purchases = $party->purchases()->where('tenant_id', $user->tenant_id)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))->get()->map(fn ($r) => ['id' => $r->id, 'date' => $r->created_at->toDateString(), 'type' => 'فاتورة شراء', 'amount' => $r->total, 'notes' => $r->invoice_number ?? '']);
        $purchaseReturns = $party->purchaseReturns()->where('tenant_id', $user->tenant_id)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))->get()->map(fn ($r) => ['id' => $r->id, 'date' => $r->created_at->toDateString(), 'type' => 'مرتجع مشتريات', 'amount' => -abs((float) $r->total), 'notes' => $r->reference_number]);
        $payments = $party->payments()->where('tenant_id', $user->tenant_id)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))->get()->map(fn ($r) => ['id' => $r->id, 'date' => $r->payment_date->toDateString(), 'type' => $r->type === 'receipt' ? 'قبض' : 'دفع', 'amount' => $r->amount, 'notes' => $r->notes ?? $r->voucher_number]);

        return response()->json(['party' => $party, 'opening_balance' => (float) $party->opening_balance, 'current_balance' => (float) $party->current_balance, 'transactions' => $orders->concat($purchases)->concat($purchaseReturns)->concat($payments)->sortBy('date')->values()]);
    }
}
