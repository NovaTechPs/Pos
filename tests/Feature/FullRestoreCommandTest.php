<?php

use App\Models\Tenant;
use Database\Seeders\FullRestoreSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

test('fresh restore refuses SQLite before clearing any tables', function () {
    $tenant = Tenant::create(['name' => 'Keep existing data']);
    $this->artisan('wasla:restore-fresh', ['--force' => true])->assertFailed();
    expect($tenant->fresh()->name)->toBe('Keep existing data');
});

test('a missing restore file is rejected before schema deletion', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    config(['client-setup.restore_file' => __DIR__.'/missing-restore.sql']);
    expect(fn () => (new FullRestoreSeeder)->prepare())->toThrow(RuntimeException::class, 'Upload the full restore SQL file');
});

test('an altered restore file is rejected before schema deletion', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    config(['client-setup.restore_file' => __FILE__, 'client-setup.restore_sha256' => str_repeat('0', 64)]);
    expect(fn () => (new FullRestoreSeeder)->prepare())->toThrow(RuntimeException::class, 'does not match');
});

test('preflight loads the approved file without running SQL', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    config(['client-setup.restore_file' => __FILE__, 'client-setup.restore_sha256' => hash_file('sha256', __FILE__)]);
    expect((new FullRestoreSeeder)->prepare())->toBe(file_get_contents(__FILE__));
});

test('verified SQL restores atomically through the database connection', function () {
    $manager = DB::getFacadeRoot();
    $pdo = new PDO('sqlite::memory:');
    $sql = 'BEGIN; CREATE TABLE restore_probe (id INTEGER); INSERT INTO restore_probe VALUES (7); COMMIT;';
    config(['client-setup.restore_sha256' => hash('sha256', $sql)]);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getPdo')->andReturn($pdo);
    DB::shouldReceive('connection')->andReturn($connection);
    DB::shouldReceive('transactionLevel')->andReturn(0);
    try {
        (new FullRestoreSeeder)->restore($sql);
        expect($pdo->query('SELECT id FROM restore_probe')->fetchColumn())->toBe(7)
            ->and($pdo->inTransaction())->toBeFalse();
    } finally {
        DB::swap($manager);
    }
});

test('an SQL error rolls back the import transaction', function () {
    $manager = DB::getFacadeRoot();
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = 'BEGIN; CREATE TABLE restore_probe (id INTEGER); INSERT INTO missing_restore_table VALUES (7); COMMIT;';
    config(['client-setup.restore_sha256' => hash('sha256', $sql)]);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getPdo')->andReturn($pdo);
    DB::shouldReceive('connection')->andReturn($connection);
    DB::shouldReceive('transactionLevel')->andReturn(0);
    try {
        expect(fn () => (new FullRestoreSeeder)->restore($sql))->toThrow(PDOException::class);
        expect($pdo->inTransaction())->toBeFalse()
            ->and($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'restore_probe'")->fetchColumn())->toBe(0);
    } finally {
        DB::swap($manager);
    }
});
