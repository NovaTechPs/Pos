<?php

namespace App\Console\Commands;

use App\Models\BranchSyncOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('branch-sync:retry {operation_uuid : Operation UUID}')]
#[Description('Retry a conflicted branch operation after resolving its cause.')]
class RetryBranchSyncOperation extends Command
{
    public function handle(): int
    {
        if (config('branch-sync.mode') !== 'edge') {
            $this->error('Only edge servers can retry local operations.');

            return self::FAILURE;
        }

        $operation = BranchSyncOperation::query()
            ->where('operation_uuid', $this->argument('operation_uuid'))
            ->whereIn('status', ['conflict', 'failed'])
            ->first();

        if (! $operation) {
            $this->error('A conflicted operation with that UUID was not found.');

            return self::FAILURE;
        }

        $operation->forceFill([
            'status' => 'pending',
            'processed_at' => null,
            'last_error' => null,
        ])->save();

        $this->info('Operation returned to the outbox. Run branch-sync:push or wait for the next scheduled run.');

        return self::SUCCESS;
    }
}
