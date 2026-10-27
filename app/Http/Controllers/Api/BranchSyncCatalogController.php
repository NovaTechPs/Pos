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
    public function live(Request $request): JsonResponse
    {
        abort_unless(config('branch-sync.mode') === 'central', 403);
        $device = $request->attributes->get('branchSyncDevice');
        abort_unless($device instanceof BranchSyncDevice, 401);
        $validated = $request->validate([
            'entity' => ['required', Rule::in(['products', 'customers'])],
            'search' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        $products = $validated['entity'] === 'products';
        $query = DB::table($products ? 'products' : 'parties')
            ->where('tenant_id', $device->tenant_id)->whereNull('deleted_at');
        if ($products) {
            $query->whereIn('id', DB::table('branch_products')->where('tenant_id', $device->tenant_id)
                ->where('branch_id', $device->branch_id)->select('product_id'));
            if (! empty($validated['category_id'])) {
                $query->where('category_id', $validated['category_id']);
            }
            if (! empty($validated['barcode'])) {
                $query->whereIn('id', DB::table('product_barcodes')->where('tenant_id', $device->tenant_id)
                    ->where('barcode', $validated['barcode'])->select('product_id'));
            }
        } else {
            $query->whereIn('type', ['customer', 'both'])->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $device->branch_id));
        }
        if (isset($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }
        $search = trim($validated['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($query) use ($search, $products, $device): void {
                if (! $products) {
                    $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%");

                    return;
                }
                $query->where(function ($query) use ($search): void {
                    foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $index => $keyword) {
                        $query->where('name', 'like', $index === 0 ? "{$keyword}%" : "%{$keyword}%");
                    }
                })->orWhere('product_number', 'like', "{$search}%")
                    ->when(ctype_digit($search), fn ($query) => $query->orWhere('id', (int) $search))
                    ->orWhereIn('id', DB::table('product_barcodes')->where('tenant_id', $device->tenant_id)
                        ->where('barcode', 'like', "{$search}%")->select('product_id'));
            });
        }
        $rows = $query->orderBy('name')->orderBy('id')->limit(isset($validated['ids']) ? 100 : 40)->get();
        $ids = $rows->pluck('id');

        return response()->json([
            'version' => 1, 'entity' => $validated['entity'],
            'tenant_id' => $device->tenant_id, 'branch_id' => $device->branch_id,
            'rows' => $rows,
            'settings' => [
                'allow_negative_stock' => TenantSetting::getBool($device->tenant_id, 'allow_negative_stock'),
                'unified_stock' => TenantSetting::getBool($device->tenant_id, 'unified_stock'),
                'merge_similar_products' => TenantSetting::getBool($device->tenant_id, 'merge_similar_products'),
            ],
            'categories' => $products ? DB::table('categories')->where('tenant_id', $device->tenant_id)->get() : [],
            'barcodes' => $products ? DB::table('product_barcodes')->where('tenant_id', $device->tenant_id)->whereIn('product_id', $ids)->get() : [],
            'prices' => $products ? DB::table('branch_products')->where('tenant_id', $device->tenant_id)
                ->where('branch_id', $device->branch_id)->whereIn('product_id', $ids)->get() : [],
            'offers' => $products ? DB::table('product_offers')->where('tenant_id', $device->tenant_id)
                ->where('branch_id', $device->branch_id)->whereIn('product_id', $ids)->whereNull('deleted_at')->get() : [],
            'available_stock' => $products ? DB::table('branch_products')->where('tenant_id', $device->tenant_id)
                ->whereIn('product_id', $ids)->selectRaw('product_id, SUM(stock_quantity) as quantity')->groupBy('product_id')->pluck('quantity', 'product_id') : [],
        ]);
    }

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
