<?php

use App\Services\SchemaIntegrity;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

test('all native check constraints compile on PostgreSQL with real boolean columns', function (): void {
    $module = getenv('WASLA_PGLITE_MODULE') ?: sys_get_temp_dir().'/wasla-pg-upgrade-test-tools/node_modules/@electric-sql/pglite/dist/index.cjs';
    if (! is_file($module)) {
        $this->markTestSkipped('Set WASLA_PGLITE_MODULE to run PostgreSQL constraint checks.');
    }
    $checks = (new ReflectionClass(SchemaIntegrity::class))->getConstant('CHECKS');
    $tables = [];
    foreach ($checks as $table => $constraints) {
        $tables[$table] = ['columns' => Schema::getColumns($table), 'checks' => $constraints];
    }
    $script = <<<'JS'
    const assert = require('node:assert/strict');
    const fs = require('node:fs');
    const { PGlite } = require(process.argv[1]);
    const tables = JSON.parse(fs.readFileSync(0, 'utf8'));
    const q = name => '"' + name.replaceAll('"', '""') + '"';
    function type(column) {
      const value = column.type.toLowerCase();
      if (value.includes('bool') || value.includes('tinyint')) return 'boolean';
      if (value.includes('datetime')) return 'timestamp';
      if (value === 'integer') return 'bigint';
      if (value.includes('blob')) return 'bytea';
      return value;
    }
    (async () => {
      const db = new PGlite();
      for (const [table, definition] of Object.entries(tables)) {
        const columns = definition.columns.map(c => q(c.name) + ' ' + type(c));
        const constraints = Object.entries(definition.checks).map(([name, expression]) =>
          'CONSTRAINT ' + q(table + '_' + name + '_check') + ' CHECK (' + expression + ')');
        try { await db.exec('CREATE TABLE ' + q(table) + ' (' + [...columns, ...constraints].join(',') + ')'); }
        catch (e) { throw new Error(table + ': ' + e.message); }
      }
      await db.exec(`INSERT INTO employee_departures (start_time,end_time,ends_next_day,minutes) VALUES ('22:00','01:00',TRUE,180)`);
      await db.exec(`INSERT INTO employee_departures (start_time,end_time,ends_next_day,minutes) VALUES ('09:00','10:00',FALSE,60)`);
      await assert.rejects(db.exec(`INSERT INTO employee_departures (start_time,end_time,ends_next_day,minutes) VALUES ('22:00','01:00',FALSE,180)`), /check constraint/);
      assert.equal((await db.query('SELECT COUNT(*)::int AS n FROM employee_departures')).rows[0].n, 2);
      await db.close();
      console.log('ALL_POSTGRES_CHECKS_OK');
    })().catch(e => { console.error(e.message); process.exitCode = 1; });
    JS;
    $process = new Process(['node', '-e', $script, $module], base_path());
    $process->setInput(json_encode($tables, JSON_THROW_ON_ERROR));
    $process->setTimeout(60);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('ALL_POSTGRES_CHECKS_OK');
});
