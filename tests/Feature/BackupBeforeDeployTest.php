<?php

use App\Services\SystemBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['filesystems.disks.backups' => ['driver' => 's3'], 'backup.disk' => 'backups', 'backup.keep' => 3]);
    Storage::fake('backups');
    $this->archive = storage_path('framework/testing/deploy-backup-'.uniqid().'.zip');
    File::ensureDirectoryExists(dirname($this->archive));
    File::put($this->archive, 'zip-bytes');
    $this->backups = Mockery::mock(SystemBackupService::class);
    $this->app->instance(SystemBackupService::class, $this->backups);
});

afterEach(function (): void {
    File::delete($this->archive);
});

test('it skips the backup when no migrations are pending', function (): void {
    $this->backups->shouldNotReceive('create');

    $this->artisan('backup:before-deploy')->expectsOutputToContain('No pending migrations')->assertSuccessful();
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

test('it uploads a backup before pending migrations and removes the local copy', function (): void {
    $directory = storage_path('framework/testing/pending-migrations');
    File::ensureDirectoryExists($directory);
    File::put($directory.'/2099_01_01_000000_pending_change.php', '<?php return new class extends Illuminate\Database\Migrations\Migration {};');
    app('migrator')->path($directory);
    $this->backups->shouldReceive('create')->once()->andReturn($this->archive);

    try {
        $this->artisan('backup:before-deploy')->expectsOutputToContain('before 1 pending migration(s)')->assertSuccessful();
    } finally {
        File::deleteDirectory($directory);
    }

    $files = Storage::disk('backups')->files('deploy-backups');
    expect($files)->toHaveCount(1)
        ->and(Storage::disk('backups')->get($files[0]))->toBe('zip-bytes')
        ->and(File::exists($this->archive))->toBeFalse();
});

test('it keeps only the newest backups', function (): void {
    foreach (['2026-01-01_000000-a', '2026-02-01_000000-b', '2026-03-01_000000-c'] as $name) {
        Storage::disk('backups')->put("deploy-backups/{$name}.zip", 'old');
    }
    $this->backups->shouldReceive('create')->once()->andReturn($this->archive);

    $this->artisan('backup:before-deploy', ['--force' => true])->assertSuccessful();

    $files = Storage::disk('backups')->files('deploy-backups');
    expect($files)->toHaveCount(3)->not->toContain('deploy-backups/2026-01-01_000000-a.zip');
});

test('a failed backup stops the deploy before migrations run', function (): void {
    $this->backups->shouldReceive('create')->once()->andThrow(new RuntimeException('pg_dump missing'));

    $this->artisan('backup:before-deploy', ['--force' => true])->expectsOutputToContain('pg_dump missing')->assertFailed();
});

test('without external storage it warns and lets the deploy continue', function (): void {
    config(['backup.disk' => 'local']);
    $this->backups->shouldNotReceive('create');

    $this->artisan('backup:before-deploy', ['--force' => true])->expectsOutputToContain('BACKUP_DISK=s3')->assertSuccessful();
});
