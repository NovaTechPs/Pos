<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class SystemBackupService
{
    public function create(): string
    {
        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory, 0700);
        $basePath = $directory.'/'.Str::uuid();
        $sqlPath = $basePath.'.sql';
        $archivePath = $basePath.'.zip';

        try {
            $this->dumpDatabase($sqlPath);
            $archive = new ZipArchive;
            if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Unable to create backup archive.');
            }
            try {
                if (! $archive->addFile($sqlPath, 'database/backup.sql')) {
                    throw new RuntimeException('Unable to add database backup.');
                }
                $disk = Storage::disk('public');
                foreach ($disk->allFiles() as $file) {
                    if (! $archive->addFile($disk->path($file), 'storage/app/public/'.$file)) {
                        throw new RuntimeException('Unable to add uploaded file.');
                    }
                }
            } finally {
                if (! $archive->close()) {
                    throw new RuntimeException('Unable to finalize backup archive.');
                }
            }

            return $archivePath;
        } catch (Throwable $exception) {
            File::delete($archivePath);
            throw $exception;
        } finally {
            File::delete($sqlPath);
        }
    }

    private function dumpDatabase(string $path): void
    {
        $configuration = app('db')->connection()->getConfig();
        $driver = $configuration['driver'];
        $host = (string) ($configuration['host'] ?? 'localhost');
        $username = (string) ($configuration['username'] ?? '');
        $database = (string) ($configuration['database'] ?? '');
        $password = (string) ($configuration['password'] ?? '');

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $command = [config('backup.mysql_binary'), '--single-transaction', '--quick', '--skip-lock-tables',
                '--host='.$host, '--port='.($configuration['port'] ?? 3306), '--user='.$username,
                '--result-file='.$path, '--databases', $database];
            $environment = ['MYSQL_PWD' => $password];
        } elseif ($driver === 'pgsql') {
            $command = [config('backup.pgsql_binary'), '--host='.$host, '--port='.($configuration['port'] ?? 5432),
                '--username='.$username, '--no-password', '--file='.$path, '--dbname='.$database];
            $environment = ['PGPASSWORD' => $password];
        } else {
            throw new RuntimeException('Database backups require MySQL, MariaDB or PostgreSQL.');
        }
        $result = Process::env($environment)->timeout(300)->quietly()->run($command);
        if (! $result->successful() || ! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Database backup failed. Check the configured backup utility and database access.');
        }
    }
}
