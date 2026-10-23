<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleOperationController extends Controller
{
    public function show(Request $request, string $operationUuid): JsonResponse
    {
        $user = $request->user();
        $operation = DB::table('api_sale_operations')->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)->where('operation_uuid', $operationUuid)
            ->when($user->branch_id && ! $user->isTenantOwner(), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->first();
        abort_unless($operation !== null, 404);

        return response()->json([
            'status' => 'confirmed',
            'result' => json_decode($operation->response_body, true, 512, JSON_THROW_ON_ERROR),
        ]);
    }
}
