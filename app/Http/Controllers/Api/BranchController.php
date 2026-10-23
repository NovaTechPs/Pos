<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branches = Branch::query()->where('tenant_id', $user->tenant_id)
            ->where('type', 'branch')
            ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')->get(['id', 'tenant_id', 'name', 'phone', 'address', 'type']);

        return response()->json(['branches' => $branches]);
    }
}
