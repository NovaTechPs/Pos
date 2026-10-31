<?php

namespace App\Console\Commands;

use App\Models\BranchSyncOperation;
use App\Services\BranchSyncCredentials;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('branch-sync:push {--tenant=} {--branch=} {--operation=}')]
#[Description('Send pending local branch operations to the central server.')]
class PushBranchSyncOperations extends Command
{
    public function handle(BranchSyncCredentials $credentials): int
    {
        if (config('branch-sync.mode') !== 'edge') {
            $this->error('Branch synchronization push is available only in edge mode.');

            return self::FAILURE;
        }

        $connections = $credentials->all();
        if ($connections === []) {
            $this->error('Sign in online with an employee account to prepare branch synchronization.');

            return self::FAILURE;
        }
        $result = self::SUCCESS;
        foreach ($connections as $connection) {
            if (($this->option('tenant') !== null && $connection['tenant_id'] !== (int) $this->option('tenant'))
                || ($this->option('branch') !== null && $connection['branch_id'] !== (int) $this->option('branch'))) {
                continue;
            }
            if ($this->pushForBranch($connection) !== self::SUCCESS) {
                $result = self::FAILURE;
            }
        }

        return $result;
    }

    /** @param array{tenant_id: int, branch_id: int, token: string} $connection */
    private function pushForBranch(array $connection): int
    {
        $serverUrl = rtrim((string) config('branch-sync.server_url'), '/');
        $token = $connection['token'];

        if ($serverUrl === '' || $token === '') {
            $this->error('Set BRANCH_SYNC_SERVER_URL and BRANCH_SYNC_TOKEN on this branch server.');

            return self::FAILURE;
        }

        if (parse_url($serverUrl, PHP_URL_SCHEME) !== 'https') {
            $this->error('BRANCH_SYNC_SERVER_URL must use HTTPS to protect the device token.');

            return self::FAILURE;
        }

        $pending = BranchSyncOperation::query()
            ->when($connection['tenant_id'] > 0, fn ($query) => $query->where('tenant_id', $connection['tenant_id']))
            ->when($connection['branch_id'] > 0, fn ($query) => $query->where('branch_id', $connection['branch_id']))
            ->where('status', 'pending');
        if ($this->option('operation') !== null) {
            $target = (clone $pending)->where('operation_uuid', $this->option('operation'))->first();
            if (! $target) {
                return self::SUCCESS;
            }
            $hasEarlierReturn = (clone $pending)->where('id', '<', $target->id)
                ->where('operation_type', 'sale.upsert')->where('payload->order->type', 'return')->exists();
            if ($hasEarlierReturn) {
                $pending->where('id', '<=', $target->id);
            } else {
                $pending->whereKey($target->id);
            }
        }
        $operations = $pending
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
                ->connectTimeout(config('app.desktop') ? 1 : 3)
                ->timeout(config('app.desktop') ? 4 : 20)
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
