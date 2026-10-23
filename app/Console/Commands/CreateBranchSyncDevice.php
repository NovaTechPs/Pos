<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\BranchSyncDevice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('branch-sync:create-device {branch : Branch ID} {name : Device name}')]
#[Description('Create a device credential for a branch synchronization node.')]
class CreateBranchSyncDevice extends Command
{
    public function handle(): int
    {
        $branch = Branch::query()->whereKey($this->argument('branch'))->first();

        if (! $branch) {
            $this->error('Branch not found.');

            return self::FAILURE;
        }

        $plainTextToken = Str::random(64);
        $device = BranchSyncDevice::query()->create([
            'device_uuid' => (string) Str::uuid(),
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'name' => $this->argument('name'),
            'token_hash' => hash('sha256', $plainTextToken),
        ]);

        $this->info("Device created: {$device->device_uuid}");
        $this->line('Save this token in the branch server environment as BRANCH_SYNC_TOKEN. It will not be shown again.');
        $this->line($plainTextToken);

        return self::SUCCESS;
    }
}
