<?php

use App\Services\PosProductSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

test('stock lookup has a tenant product branch index', function (): void {
    $index = collect(Schema::getIndexes('branch_products'))->firstWhere('name', 'branch_products_tenant_product_branch_index');
    expect($index['columns'])->toBe(['tenant_id', 'product_id', 'branch_id']);
});

test('PostgreSQL fast Arabic normalization matches existing search and barcode index compiles', function (): void {
    $module = getenv('WASLA_PGLITE_MODULE') ?: sys_get_temp_dir().'/wasla-pg-upgrade-test-tools/node_modules/@electric-sql/pglite/dist/index.cjs';
    if (! is_file($module)) {
        $this->markTestSkipped('Set WASLA_PGLITE_MODULE to run PostgreSQL search checks.');
    }
    $method = new ReflectionMethod(PosProductSearch::class, 'normalizedColumn');
    $search = app(PosProductSearch::class);
    $fast = $method->invoke($search, 'products.name', true);
    $slow = $method->invoke($search, 'products.name', false);
    $statements = [];
    $manager = DB::getFacadeRoot();
    $schema = Schema::getFacadeRoot();
    $connection = DB::connection();
    DB::shouldReceive('connection')->andReturn($connection);
    DB::shouldReceive('getDriverName')->andReturn('pgsql');
    DB::shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$statements): bool {
        $statements[] = $sql;

        return true;
    });
    Schema::shouldReceive('table')->once();
    try {
        (require database_path('migrations/2026_11_01_115622_add_pos_stock_lookup_indexes.php'))->up();
    } finally {
        DB::swap($manager);
        Schema::swap($schema);
    }
    $script = <<<'JS'
    const fs = require('node:fs');
    const assert = require('node:assert/strict');
    const { PGlite } = require(process.argv[1]);
    const data = JSON.parse(fs.readFileSync(0, 'utf8'));
    (async () => {
      const db = new PGlite();
      await db.exec('CREATE TABLE product_barcodes (tenant_id bigint, barcode text, product_id bigint)');
      for (const sql of data.statements) await db.exec(sql);
      assert.equal((await db.query("SELECT count(*)::int AS n FROM pg_indexes WHERE indexname='product_barcodes_lower_lookup_index'")).rows[0].n, 1);
      for (const name of ['أإآٱىةـًٌٍَُِّْٰ', 'علبة قَهْوَة تركية', 'إبريق أزرق', 'عَلـِيّ', 'Coffee 12', 'أَكوابْ']) {
        const row = (await db.query(`SELECT ${data.fast} AS fast, ${data.slow} AS slow FROM (SELECT $1::text AS name) products`, [name])).rows[0];
        assert.equal(row.fast, row.slow, name);
      }
      await db.close();
      console.log('POSTGRES_SEARCH_OK');
    })().catch(error => { console.error(error.message); process.exitCode = 1; });
    JS;
    $process = new Process(['node', '-e', $script, $module], base_path());
    $process->setInput(json_encode(compact('fast', 'slow', 'statements'), JSON_THROW_ON_ERROR));
    $process->setTimeout(60);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('POSTGRES_SEARCH_OK');
});
