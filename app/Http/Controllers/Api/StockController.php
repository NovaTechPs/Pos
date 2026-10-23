<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function transfers(Request $request): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $branchId = $request->get('branch_id') ?? $user->branch_id;
        abort_if(! $branchId, 422, 'حدد الفرع لعرض التحويلات.');

        $transfers = StockTransfer::where('tenant_id', $tenantId)
            ->where(function ($q) use ($branchId) {
                $q->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId);
            })
            ->with(['items.product', 'fromBranch', 'toBranch'])
            ->latest()
            ->get();

        return response()->json([
            'transfers' => $transfers,
        ]);
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_branch_id' => ['required', 'integer'],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;

        $transfer = DB::transaction(function () use ($validated, $user, $tenantId) {
            $transfer = StockTransfer::create([
                'tenant_id' => $tenantId,
                'from_branch_id' => $validated['from_branch_id'],
                'to_branch_id' => $validated['to_branch_id'],
                'transfer_number' => 'TRF-'.time().'-'.rand(100, 999),
                'status' => 'pending',
                'created_by' => $user->id,
                'transfer_date' => now(),
                'notes' => $validated['notes'] ?? 'تحويل مخزون بين الفروع',
            ]);

            foreach ($validated['items'] as $item) {
                Product::query()->where('tenant_id', $tenantId)->whereKey((int) $item['product_id'])->firstOrFail();
                StockTransferItem::create([
                    'tenant_id' => $tenantId,
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            return $transfer->load('items.product', 'fromBranch', 'toBranch');
        });

        return response()->json([
            'message' => 'تم إنشاء طلب تحويل المخزون بنجاح',
            'transfer' => $transfer,
        ], 201);
    }
}
