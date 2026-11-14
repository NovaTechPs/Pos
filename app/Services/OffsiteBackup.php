<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Uploads a full backup archive to the external backup disk, checks that the uploaded copy can be
 * restored from, and keeps only the newest copies in each folder.
 */
class OffsiteBackup
{
    /** Tables every restorable backup must contain. */
    public const REQUIRED_TABLES = ['tenants', 'users', 'products', 'orders', 'migrations'];

    public function __construct(private SystemBackupService $backups) {}

    public function diskName(): string
    {
        return (string) config('backup.disk');
    }

    /**
     * True when the backup disk is outside the container (a local copy would vanish with it).
     */
    public function isExternal(): bool
    {
        return ! in_array(config('filesystems.disks.'.$this->diskName().'.driver'), [null, 'local'], true);
    }

    /**
     * Creates a backup, uploads it under the given folder and returns its path on the backup disk.
     */
    public function upload(string $directory): string
    {
        $archivePath = $this->backups->create();
        try {
            $name = $directory.'/'.now()->format('Y-m-d_His').'-'.Str::lower(Str::random(6)).'.zip';
            $stream = fopen($archivePath, 'rb');
            try {
                if (! Storage::disk($this->diskName())->writeStream($name, $stream)) {
                    throw new RuntimeException('The backup disk refused the upload.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $name;
        } finally {
            File::delete($archivePath);
        }
    }

    /**
     * Downloads the uploaded copy back and checks it could be restored: the archive opens, holds a
     * complete database dump, and that dump creates the core tables.
     *
     * @return array{bytes: int, tables: int}
     */
    public function verify(string $name): array
    {
        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory, 0700);
        $localPath = $directory.'/verify-'.Str::uuid().'.zip';
        try {
            $source = Storage::disk($this->diskName())->readStream($name);
            if (! is_resource($source)) {
                throw new RuntimeException('The uploaded backup could not be read back.');
            }
            $target = fopen($localPath, 'wb');
            try {
                stream_copy_to_stream($source, $target);
            } finally {
                fclose($source);
                fclose($target);
            }

            $archive = new ZipArchive;
            if ($archive->open($localPath, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('The uploaded backup is not a readable archive.');
            }
            try {
                $sql = $archive->getFromName('database/backup.sql');
            } finally {
                $archive->close();
            }
            if (! is_string($sql) || $sql === '') {
                throw new RuntimeException('The uploaded backup has no database dump.');
            }
            if (! str_contains($sql, '-- Dump completed') && ! str_contains($sql, 'PostgreSQL database dump complete')) {
                throw new RuntimeException('The database dump in the backup is incomplete.');
            }
            preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?(?:[`"]?\w+[`"]?\.)?[`"]?(\w+)[`"]?/i', $sql, $matches);
            $tables = array_unique($matches[1]);
            $missing = array_diff(self::REQUIRED_TABLES, $tables);
            if ($missing !== []) {
                throw new RuntimeException('The database dump is missing tables: '.implode(', ', $missing).'.');
            }

            return ['bytes' => (int) filesize($localPath), 'tables' => count($tables)];
        } finally {
            File::delete($localPath);
        }
    }

    public function prune(string $directory, int $keep): void
    {
        $disk = Storage::disk($this->diskName());
        $files = collect($disk->files($directory))->filter(fn (string $file): bool => str_ends_with($file, '.zip'))->sort()->values();
        $files->slice(0, max(0, $files->count() - max(1, $keep)))->each(fn (string $file) => $disk->delete($file));
    }
}
