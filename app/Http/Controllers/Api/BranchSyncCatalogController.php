<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchSyncDevice;
use App\Models\TenantSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchSyncCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(config('branch-sync.mode') === 'central', 403);
        $device = $request->attributes->get('branchSyncDevice');
        abort_unless($device instanceof BranchSyncDevice, 401);
        $validated = $request->validate([
            'entity' => ['required', Rule::in(['products', 'customers'])],
            'after' => ['sometimes', 'integer', 'min:0'],
        ]);
        $query = DB::table($validated['entity'] === 'products' ? 'products' : 'parties')
            ->where('tenant_id', $device->tenant_id)
            ->where('id', '>', $validated['after'] ?? 0);

        if ($validated['entity'] === 'products') {
            $query->whereIn('id', DB::table('branch_products')
                ->where('tenant_id', $device->tenant_id)
                ->where('branch_id', $device->branch_id)->select('product_id'));
        } else {
            $query->whereIn('type', ['customer', 'both'])
                ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $device->branch_id));
        }

        $rows = $query->orderBy('id')->limit(100)->get();
        $ids = $rows->pluck('id');

        return response()->json([
            'tenant_id' => $device->tenant_id,
            'branch_id' => $device->branch_id,
            'settings' => [
                'allow_negative_stock' => TenantSetting::getBool($device->tenant_id, 'allow_negative_stock'),
                'unified_stock' => TenantSetting::getBool($device->tenant_id, 'unified_stock'),
                'merge_similar_products' => TenantSetting::getBool($device->tenant_id, 'merge_similar_products'),
            ],
            'rows' => $rows,
            'next' => $rows->count() === 100 ? $rows->last()->id : null,
            'categories' => $validated['entity'] === 'products'
                ? DB::table('categories')->where('tenant_id', $device->tenant_id)->whereIn('id', $rows->pluck('category_id'))->get() : [],
            'barcodes' => $validated['entity'] === 'products'
                ? DB::table('product_barcodes')->where('tenant_id', $device->tenant_id)->whereIn('product_id', $ids)->get() : [],
            'prices' => $validated['entity'] === 'products'
                ? DB::table('branch_products')->where('tenant_id', $device->tenant_id)
                    ->where('branch_id', $device->branch_id)->whereIn('product_id', $ids)->get() : [],
        ]);
    }
}
