<?php

namespace App\Console\Commands;

use App\Services\OffsiteBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

#[Signature('backup:before-deploy
    {--force : Back up even when no migrations are pending}')]
#[Description('Upload a database and files backup to the backup disk before migrations run on deploy.')]
class BackupBeforeDeploy extends Command
{
    public const DIRECTORY = 'deploy-backups';

    public function handle(OffsiteBackup $backup, Migrator $migrator): int
    {
        $pending = $this->pendingMigrations($migrator);
        if ($pending === null) {
            $this->info('The database has no migrations yet; nothing to back up.');

            return self::SUCCESS;
        }
        if ($pending === 0 && ! $this->option('force')) {
            $this->info('No pending migrations; backup skipped.');

            return self::SUCCESS;
        }

        if (! $backup->isExternal()) {
            // A local copy would vanish with the container, so it is not worth blocking the deploy.
            $this->warn('Backup disk "'.$backup->diskName().'" is not external storage; set BACKUP_DISK=s3 to keep deploy backups. Continuing without a backup.');

            return self::SUCCESS;
        }

        try {
            $name = $backup->upload(self::DIRECTORY);
            $this->info("Backup uploaded before {$pending} pending migration(s): {$name}");
            $backup->prune(self::DIRECTORY, (int) config('backup.keep'));
        } catch (Throwable $exception) {
            // Failing here stops the deploy before migrate, so the running version stays live.
            $this->error('Backup before deploy failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The number of migrations that have not run yet, or null when the migrations table does not exist.
     */
    private function pendingMigrations(Migrator $migrator): ?int
    {
        if (! $migrator->repositoryExists()) {
            return null;
        }
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $ran = $migrator->getRepository()->getRan();

        return count(array_diff(array_keys($files), $ran));
    }
}
