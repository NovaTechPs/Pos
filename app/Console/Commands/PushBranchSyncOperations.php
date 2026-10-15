<?php

namespace App\Console\Commands;

use App\Models\BranchSyncOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('branch-sync:push')]
#[Description('Send pending local branch operations to the central server.')]
class PushBranchSyncOperations extends Command
{
    public function handle(): int
    {
        if (config('branch-sync.mode') !== 'edge') {
            $this->error('Branch synchronization push is available only in edge mode.');

            return self::FAILURE;
        }

        $serverUrl = rtrim((string) config('branch-sync.server_url'), '/');
        $token = (string) config('branch-sync.token');

        if ($serverUrl === '' || $token === '') {
            $this->error('Set BRANCH_SYNC_SERVER_URL and BRANCH_SYNC_TOKEN on this branch server.');

            return self::FAILURE;
        }

        if (parse_url($serverUrl, PHP_URL_SCHEME) !== 'https') {
            $this->error('BRANCH_SYNC_SERVER_URL must use HTTPS to protect the device token.');

            return self::FAILURE;
        }

        $operations = BranchSyncOperation::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($operations->isEmpty()) {
            $this->info('No pending branch operations.');

            return self::SUCCESS;
        }

        $operations->each(fn (BranchSyncOperation $operation) => $operation->increment('attempts'));

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout(20)
                ->post($serverUrl.'/api/branch-sync/operations', [
                    'operations' => $operations->map(fn (BranchSyncOperation $operation): array => [
                        'operation_uuid' => $operation->operation_uuid,
                        'operation_type' => $operation->operation_type,
                        'payload' => $operation->payload,
                    ])->all(),
                ]);
        } catch (\Throwable) {
            $this->warn('Central server is unavailable. Operations remain queued locally.');

            return self::SUCCESS;
        }

        if (! $response->successful()) {
            $this->warn('Central server did not accept the batch. Operations remain queued locally.');

            return self::SUCCESS;
        }

        $statuses = $response->json('accepted', []);
        $messages = $response->json('messages', []);
        foreach ($operations as $operation) {
            $status = $statuses[$operation->operation_uuid] ?? null;
            if (! in_array($status, ['synced', 'conflict'], true)) {
                continue;
            }

            $operation->forceFill([
                'status' => $status,
                'processed_at' => $status === 'synced' ? now() : null,
                'last_error' => $status === 'conflict'
                    ? mb_substr((string) ($messages[$operation->operation_uuid] ?? 'Central server reported a synchronization conflict.'), 0, 2000)
                    : null,
            ])->save();
        }

        $this->info('Branch synchronization batch sent.');

        return self::SUCCESS;
    }
}
