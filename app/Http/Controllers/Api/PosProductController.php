<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosProductController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'barcode' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'cost_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'retail_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'wholesale_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'stock_quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $barcode = trim($data['barcode']);
        $product = DB::transaction(function () use ($data, $barcode, $tenantId): Product {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            if ($this->barcode($tenantId, $barcode)) {
                throw ValidationException::withMessages(['barcode' => 'الباركود مسجل بالفعل. حدّث الأصناف وأعد مسحه.']);
            }
            $product = Product::create(['tenant_id' => $tenantId, 'name' => trim($data['name']),
                'category_id' => $data['category_id'] ?? null, 'cost_price' => $data['cost_price']]);
            ProductBarcode::create(['tenant_id' => $tenantId, 'product_id' => $product->id, 'barcode' => $barcode]);
            foreach (Branch::query()->where('tenant_id', $tenantId)->pluck('id') as $branchId) {
                BranchProduct::create(['tenant_id' => $tenantId, 'branch_id' => $branchId, 'product_id' => $product->id,
                    'retail_price' => $data['retail_price'], 'wholesale_price' => $data['wholesale_price'],
                    'stock_quantity' => (int) $branchId === (int) $data['branch_id'] ? $data['stock_quantity'] : 0]);
            }

            return $product->load(['barcodes', 'branches']);
        });

        return response()->json(['product' => $product, 'message' => 'تمت إضافة الصنف. امسح الباركود لإضافته للفاتورة.'], 201);
    }

    public function attach(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'product_id' => ['required', 'integer'],
            'barcode' => ['required', 'string', 'max:100']]);
        $tenantId = $request->user()->tenant_id;
        $barcode = trim($data['barcode']);
        $product = DB::transaction(function () use ($data, $barcode, $tenantId): Product {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $product = Product::query()->where('tenant_id', $tenantId)->whereKey($data['product_id'])->firstOrFail();
            $existing = $this->barcode($tenantId, $barcode);
            if ($existing && (int) $existing->product_id !== (int) $product->id) {
                throw ValidationException::withMessages(['barcode' => 'تم تسجيل هذا الباركود لصنف آخر. حدّث الأصناف وأعد مسحه.']);
            }
            if (! $existing) {
                ProductBarcode::create(['tenant_id' => $tenantId, 'product_id' => $product->id, 'barcode' => $barcode]);
            }

            return $product->load(['barcodes', 'branches']);
        });

        return response()->json(['product' => $product, 'message' => 'تم ربط الباركود. امسحه مرة أخرى لإضافته للفاتورة.']);
    }

    private function barcode(int $tenantId, string $barcode): ?ProductBarcode
    {
        return ProductBarcode::query()->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(barcode) = ?', [mb_strtolower($barcode)])->first();
    }
}
