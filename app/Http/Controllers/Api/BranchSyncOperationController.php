<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchSyncDevice;
use App\Models\BranchSyncOperation;
use App\Services\BranchSyncOperationProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BranchSyncOperationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:100'],
            'operations.*.operation_uuid' => ['required', 'uuid'],
            'operations.*.operation_type' => [
                'required',
                'string',
                Rule::in(['sale.upsert', 'shift.opened', 'shift.closed']),
            ],
            'operations.*.payload' => ['required', 'array'],
        ]);

        $device = $request->attributes->get('branchSyncDevice');

        if (! $device instanceof BranchSyncDevice) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $accepted = [];
        $messages = [];

        foreach ($validated['operations'] as $operation) {
            if (strlen(json_encode($operation['payload'], JSON_THROW_ON_ERROR)) > 1_000_000) {
                return response()->json(['message' => 'An operation payload exceeds the 1 MB limit.'], 413);
            }

            $result = DB::transaction(function () use ($device, $operation): string {
                $existing = BranchSyncOperation::query()
                    ->where('branch_id', $device->branch_id)
                    ->where('operation_uuid', $operation['operation_uuid'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $sameType = $existing->operation_type === $operation['operation_type'];
                    $existingPayload = $existing->getAttribute('payload');
                    $samePayload = is_array($existingPayload)
                        && $this->normalizePayload($existingPayload) === $this->normalizePayload($operation['payload']);

                    if (! $sameType || ! $samePayload) {
                        return 'conflict';
                    }

                    if (in_array($existing->status, ['synced', 'failed'], true)) {
                        return $existing->status;
                    }

                    if ($existing->status === 'conflict') {
                        $existing->forceFill(['status' => 'pending', 'last_error' => null])->save();
                    }

                    return $this->processOperation($existing);
                }

                $created = BranchSyncOperation::query()->create([
                    'tenant_id' => $device->tenant_id,
                    'branch_id' => $device->branch_id,
                    'device_id' => $device->id,
                    'operation_uuid' => $operation['operation_uuid'],
                    'operation_type' => $operation['operation_type'],
                    'status' => 'pending',
                    'payload' => $operation['payload'],
                    'received_at' => now(),
                ]);

                return $this->processOperation($created);
            });

            $accepted[$operation['operation_uuid']] = $result;

            if ($result === 'conflict') {
                $messages[$operation['operation_uuid']] = BranchSyncOperation::query()
                    ->where('branch_id', $device->branch_id)
                    ->where('operation_uuid', $operation['operation_uuid'])
                    ->value('last_error') ?: 'UUID العملية مستخدم مسبقًا بمحتوى مختلف.';
            }
        }

        return response()->json([
            'accepted' => $accepted,
            'messages' => $messages,
        ]);
    }

    private function processOperation(BranchSyncOperation $operation): string
    {
        $operation->increment('attempts');

        try {
            DB::transaction(function () use ($operation): void {
                app(BranchSyncOperationProcessor::class)->process($operation);
            });

            $operation->forceFill([
                'status' => 'synced',
                'processed_at' => now(),
                'last_error' => null,
            ])->save();

            return 'synced';
        } catch (ValidationException|RuntimeException $exception) {
            $operation->forceFill([
                'status' => 'conflict',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            return 'conflict';
        }
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizePayload($value);
            }
        }

        if (! array_is_list($payload)) {
            ksort($payload);
        }

        return $payload;
    }
}
