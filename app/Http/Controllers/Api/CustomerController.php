<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->listParties($request, 'customer');
    }

    public function suppliers(Request $request): JsonResponse
    {
        return $this->listParties($request, 'supplier');
    }

    private function listParties(Request $request, string $type): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;

        $customers = Party::where('tenant_id', $tenantId)
            ->whereIn('type', $type === 'customer' ? ['customer', 'both'] : ['supplier', 'both'])
            ->where('is_active', true)
            ->when($user->branch_id && ! $user->isTenantOwner(), function ($query) use ($user): void {
                $query->where(function ($branchQuery) use ($user): void {
                    $branchQuery->where('branch_id', $user->branch_id)->orWhereNull('branch_id');
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json([$type === 'customer' ? 'customers' : 'suppliers' => $customers]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->storeParty($request, 'customer');
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        return $this->storeParty($request, 'supplier');
    }

    private function storeParty(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
        ]);

        $user = $request->user() ?? auth()->user();
        $tenantId = $user->tenant_id;
        $branchId = $user->branch_id;

        $customer = Party::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'address' => $validated['address'] ?? null,
            'type' => $type,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'تم إضافة العميل بنجاح',
            $type => $customer,
        ], 201);
    }
}
