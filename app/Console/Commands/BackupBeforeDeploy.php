<?php

namespace App\Console\Commands;

use App\Services\SystemBackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

#[Signature('backup:before-deploy
    {--force : Back up even when no migrations are pending}')]
#[Description('Upload a database and files backup to the backup disk before migrations run on deploy.')]
class BackupBeforeDeploy extends Command
{
    public const DIRECTORY = 'deploy-backups';

    public function handle(SystemBackupService $backups, Migrator $migrator): int
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

        $diskName = (string) config('backup.disk');
        if (in_array(config("filesystems.disks.{$diskName}.driver"), [null, 'local'], true)) {
            // A local copy would vanish with the container, so it is not worth blocking the deploy.
            $this->warn("Backup disk \"{$diskName}\" is not external storage; set BACKUP_DISK=s3 to keep deploy backups. Continuing without a backup.");

            return self::SUCCESS;
        }

        $archivePath = null;
        try {
            $archivePath = $backups->create();
            $name = self::DIRECTORY.'/'.now()->format('Y-m-d_His').'-'.Str::lower(Str::random(6)).'.zip';
            $stream = fopen($archivePath, 'rb');
            try {
                if (! Storage::disk($diskName)->writeStream($name, $stream)) {
                    throw new \RuntimeException('The backup disk refused the upload.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $this->info("Backup uploaded before {$pending} pending migration(s): {$name}");
            $this->pruneOldBackups($diskName);
        } catch (Throwable $exception) {
            // Failing here stops the deploy before migrate, so the running version stays live.
            $this->error('Backup before deploy failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        } finally {
            if ($archivePath !== null) {
                File::delete($archivePath);
            }
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

    private function pruneOldBackups(string $diskName): void
    {
        $keep = max(1, (int) config('backup.keep'));
        $disk = Storage::disk($diskName);
        $files = collect($disk->files(self::DIRECTORY))->filter(fn (string $file): bool => str_ends_with($file, '.zip'))->sort()->values();
        $files->slice(0, max(0, $files->count() - $keep))->each(fn (string $file) => $disk->delete($file));
    }
}
