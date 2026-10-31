<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FullRestoreSeeder extends Seeder
{
    public function prepare(): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Full restore requires PostgreSQL. No database was cleared.');
        }
        $path = config('client-setup.restore_file');
        $expectedHash = config('client-setup.restore_sha256');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Upload the full restore SQL file to CLIENT_RESTORE_FILE before restoring.');
        }
        if (! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/i', $expectedHash) !== 1) {
            throw new RuntimeException('Configure CLIENT_RESTORE_SHA256 before restoring.');
        }
        $sql = file_get_contents($path);
        if ($sql === false || ! hash_equals(strtolower($expectedHash), hash('sha256', $sql))) {
            throw new RuntimeException('The restore file does not match the approved SHA256. No database was cleared.');
        }

        return $sql;
    }

    public function restore(string $sql): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Run the PostgreSQL restore outside any existing transaction.');
        }
        $expectedHash = config('client-setup.restore_sha256');
        if (! is_string($expectedHash) || ! hash_equals(strtolower($expectedHash), hash('sha256', $sql))) {
            throw new RuntimeException('Restore file verification failed.');
        }
        try {
            // The approved SQL owns BEGIN/COMMIT and refuses a non-empty database.
            if (DB::connection()->getPdo()->exec($sql) === false) {
                throw new RuntimeException('The approved restore script could not be executed.');
            }
        } catch (Throwable $exception) {
            if (DB::connection()->getPdo()->inTransaction()) {
                DB::connection()->getPdo()->rollBack();
            }
            throw $exception;
        }
    }

    public function run(): void
    {
        $this->restore($this->prepare());
    }
}
