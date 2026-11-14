<?php

use App\Services\SystemBackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['filesystems.disks.backups' => ['driver' => 's3'], 'backup.disk' => 'backups', 'backup.daily_keep' => 2,
        'services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '42']);
    Storage::fake('backups');
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $this->archive = storage_path('framework/testing/daily-backup-'.uniqid().'.zip');
    File::ensureDirectoryExists(dirname($this->archive));
    $this->makeArchive = function (string $sql): void {
        $zip = new ZipArchive;
        $zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database/backup.sql', $sql);
        $zip->close();
    };
    $this->backups = Mockery::mock(SystemBackupService::class);
    $this->app->instance(SystemBackupService::class, $this->backups);
});

afterEach(function (): void {
    File::delete($this->archive);
});

function completeDump(array $tables = ['tenants', 'users', 'products', 'orders', 'migrations']): string
{
    return collect($tables)->map(fn (string $table): string => "CREATE TABLE `{$table}` (`id` bigint);")->implode("\n")
        ."\n-- Dump completed on 2026-11-14 02:30:00\n";
}

test('the daily backup is uploaded, checked and reported on Telegram', function (): void {
    ($this->makeArchive)(completeDump());
    $this->backups->shouldReceive('create')->once()->andReturn($this->archive);

    $this->artisan('backup:daily')->expectsOutputToContain('uploaded and verified')->assertSuccessful();

    expect(Storage::disk('backups')->files('daily-backups'))->toHaveCount(1)
        ->and(File::exists($this->archive))->toBeFalse();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'bottest-token/sendMessage')
        && $request['chat_id'] === '42' && str_contains($request['text'], 'نجح فحص الاستعادة'));
});

test('a backup whose dump is cut off fails the restore check and alerts on Telegram', function (): void {
    ($this->makeArchive)("CREATE TABLE `tenants` (`id` bigint);\n");
    $this->backups->shouldReceive('create')->once()->andReturn($this->archive);

    $this->artisan('backup:daily')->expectsOutputToContain('incomplete')->assertFailed();

    Http::assertSent(fn ($request): bool => str_contains($request['text'], 'فشلت النسخة الاحتياطية اليومية'));
});

test('a backup missing core tables fails the restore check', function (): void {
    ($this->makeArchive)(completeDump(['tenants', 'users', 'migrations']));
    $this->backups->shouldReceive('create')->once()->andReturn($this->archive);

    $this->artisan('backup:daily')->expectsOutputToContain('missing tables: products, orders')->assertFailed();
});

test('only the newest daily backups are kept', function (): void {
    foreach (['2026-01-01_000000-a', '2026-02-01_000000-b'] as $name) {
        Storage::disk('backups')->put("daily-backups/{$name}.zip", 'old');
    }
    ($this->makeArchive)(completeDump());
    $this->backups->shouldReceive('create')->once()->andReturn($this->archive);

    $this->artisan('backup:daily')->assertSuccessful();

    expect(Storage::disk('backups')->files('daily-backups'))->toHaveCount(2)
        ->not->toContain('daily-backups/2026-01-01_000000-a.zip');
});

test('the daily backup refuses to run without external storage', function (): void {
    config(['backup.disk' => 'local']);
    $this->backups->shouldNotReceive('create');

    $this->artisan('backup:daily')->assertFailed();

    Http::assertSent(fn ($request): bool => str_contains($request['text'], 'BACKUP_DISK'));
});
