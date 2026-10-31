<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('PostgreSQL stock ledger survives fresh rebuilds and repeated initialization', function (): void {
    $module = getenv('WASLA_PGLITE_MODULE') ?: sys_get_temp_dir().'/wasla-pg-upgrade-test-tools/node_modules/@electric-sql/pglite/dist/index.cjs';
    if (! is_file($module)) {
        $this->markTestSkipped('Set WASLA_PGLITE_MODULE to run PostgreSQL regression checks.');
    }
    $queries = [];
    $manager = DB::getFacadeRoot();
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('exec')->andReturnUsing(function (string $sql) use (&$queries): int {
        $queries[] = $sql;

        return 0;
    });
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getPdo')->andReturn($pdo);
    DB::shouldReceive('connection')->andReturn($connection);
    try {
        SchemaIntegrity::stockLedger();
    } finally {
        DB::swap($manager);
    }
    $script = <<<'JS'
    const assert = require('node:assert/strict');
    const fs = require('node:fs');
    const { PGlite } = require(process.argv[1]);
    const statements = JSON.parse(fs.readFileSync(0, 'utf8'));
    (async () => {
      const db = new PGlite();
      const ddl = `CREATE TABLE branch_products (
        id bigint primary key, tenant_id bigint, branch_id bigint, product_id bigint,
        stock_quantity numeric, stock_source_type text, stock_source_id bigint,
        stock_source_reference text, stock_operation_uuid uuid, stock_actor_id bigint);
        CREATE TABLE stock_movements (
        id bigserial primary key, tenant_id bigint, branch_id bigint, product_id bigint,
        branch_product_id bigint, quantity_before numeric, quantity_after numeric,
        quantity numeric, source_type text, source_id bigint, source_reference text,
        operation_uuid uuid, recorded_by bigint, created_at timestamp);`;
      for (let cycle = 0; cycle < 3; cycle++) {
        await db.exec(ddl);
        for (const sql of statements) await db.exec(sql);
        for (const sql of statements) await db.exec(sql);
        await db.exec('INSERT INTO branch_products (id,tenant_id,branch_id,product_id,stock_quantity) VALUES (1,1,1,1,10)');
        await db.exec('UPDATE branch_products SET stock_quantity = 7 WHERE id = 1');
        assert.equal((await db.query('SELECT COUNT(*)::int AS n FROM stock_movements')).rows[0].n, 2);
        assert.equal(Number((await db.query('SELECT quantity FROM stock_movements ORDER BY id DESC LIMIT 1')).rows[0].quantity), -3);
        await assert.rejects(db.exec("UPDATE stock_movements SET source_type = 'tampered'"), /immutable/);
        await assert.rejects(db.exec('DELETE FROM stock_movements'), /immutable/);
        // Re-initialization with existing triggers must not double-record changes.
        for (const sql of statements) await db.exec(sql);
        await db.exec('UPDATE branch_products SET stock_quantity = 6 WHERE id = 1');
        assert.equal((await db.query('SELECT COUNT(*)::int AS n FROM stock_movements')).rows[0].n, 3);
        await db.exec('DROP TABLE branch_products, stock_movements CASCADE');
        assert.equal((await db.query("SELECT COUNT(*)::int AS n FROM pg_proc WHERE proname LIKE 'stock_ledger_%_fn'")).rows[0].n, 4);
      }
      await db.close();
      console.log('STOCK_LEDGER_REBUILD_OK');
    })().catch(error => { console.error(error.message); process.exitCode = 1; });
    JS;
    $process = new Process(['node', '-e', $script, $module], base_path());
    $process->setInput(json_encode($queries, JSON_THROW_ON_ERROR));
    $process->setTimeout(60);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('STOCK_LEDGER_REBUILD_OK');
});
