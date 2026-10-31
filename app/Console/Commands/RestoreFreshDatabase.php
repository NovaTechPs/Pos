<?php

namespace App\Console\Commands;

use Database\Seeders\FullRestoreSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use RuntimeException;

#[Signature('wasla:restore-fresh {--force : Confirm deletion of the configured PostgreSQL database}')]
#[Description('Validate the approved full restore file, rebuild the schema, and import the client data')]
class RestoreFreshDatabase extends Command
{
    use ConfirmableTrait;

    public function handle(FullRestoreSeeder $seeder): int
    {
        try {
            // Verify and load the file before migrate:fresh can delete any tables.
            $sql = $seeder->prepare();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (! $this->confirmToProceed('This deletes the configured PostgreSQL database and restores the approved client dataset.')) {
            return self::FAILURE;
        }
        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $seeder->restore($sql);
        $this->info('Restored: 5 accounts, 3 branches, 3539 products, 5638 barcodes, 10617 branch product records.');

        return self::SUCCESS;
    }
}
