<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

function legacyPostgresUpgradeFixture(): array
{
    $previous = DB::getDefaultConnection();
    config(['database.connections.pg_upgrade_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('pg_upgrade_legacy');
    try {
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (basename($file) >= '2026_10_23') {
                continue;
            }
            $process = new Process(['git', 'show', '137f031:database/migrations/'.basename($file)], base_path());
            $process->mustRun();
            $source = preg_replace('/^\xEF\xBB\xBF/', '', $process->getOutput());
            $source = preg_replace('/^\s*<\?php\s*/', '', $source);
            try {
                $migration = eval($source);
            } catch (ParseError $error) {
                throw new RuntimeException(basename($file).': '.$error->getMessage(), previous: $error);
            }
            $migration->up();
        }
        $tables = [];
        foreach (Schema::getTables() as $table) {
            if ($table['name'] === 'sqlite_sequence') {
                continue;
            }
            $tables[$table['name']] = ['columns' => array_values(Schema::getColumns($table['name'])), 'indexes' => array_values(Schema::getIndexes($table['name'])), 'foreign' => array_values(Schema::getForeignKeys($table['name']))];
        }

        return $tables;
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('pg_upgrade_legacy');
    }
}

test('postgres upgrade preserves legacy data is repeatable and enforces the new protections', function (): void {
    $module = getenv('WASLA_PGLITE_MODULE') ?: sys_get_temp_dir().'/wasla-pg-upgrade-test-tools/node_modules/@electric-sql/pglite/dist/index.cjs';
    if (! is_file($module)) {
        $this->markTestSkipped('Set WASLA_PGLITE_MODULE to an isolated PGlite installation to run PostgreSQL integration checks.');
    }
    $fixture = legacyPostgresUpgradeFixture();
    $script = <<<'JS'
    const assert = require('node:assert/strict');
    const fs = require('node:fs');
    const { PGlite } = require(process.argv[2]);
    const schema = JSON.parse(fs.readFileSync(0, 'utf8'));
    const upgrade = fs.readFileSync(process.argv[3], 'utf8');
    const execute = async (db, sql) => {
        try { return await db.exec(sql); }
        catch (error) {
            const position = Number(error.position || error.internalPosition || 0);
            console.error('SQL context:', sql.slice(Math.max(0, position - 180), position + 180), error.internalQuery || '');
            throw error;
        }
    };
    const q = value => '"' + value.replaceAll('"', '""') + '"';
    function type(column) {
        const t = column.type.toLowerCase();
        if (t.includes('tinyint') || t.includes('bool')) return 'boolean';
        if (t === 'integer' || t === 'bigint') return 'bigint';
        if (t.includes('datetime')) return 'timestamp without time zone';
        if (t.includes('numeric') || t.includes('decimal')) return t.replace('numeric', 'numeric').replace('decimal', 'numeric');
        if (t.includes('varchar')) return 'varchar(255)';
        if (t === 'float' || t === 'real') return 'double precision';
        return t;
    }
    function baseline() {
        const ddl = [], indexes = [], foreign = [];
        for (const [table, structure] of Object.entries(schema)) {
            const primary = structure.indexes.find(i => i.primary)?.columns || [];
            const columns = structure.columns.map(c => {
                let t = type(c), def = c.default;
                if (c.name === 'id' && t === 'bigint' && primary.includes('id')) { t = 'bigserial'; def = null; }
                if (t === 'boolean' && def !== null) def = ['1', "'1'", 'true'].includes(String(def).toLowerCase()) ? 'true' : 'false';
                return q(c.name) + ' ' + t + (c.nullable ? '' : ' NOT NULL') + (def === null ? '' : ' DEFAULT ' + def);
            });
            if (primary.length) columns.push('PRIMARY KEY (' + primary.map(q).join(',') + ')');
            ddl.push('CREATE TABLE ' + q(table) + ' (' + columns.join(',') + ')');
            for (const index of structure.indexes.filter(i => !i.primary)) indexes.push('CREATE ' + (index.unique ? 'UNIQUE ' : '') + 'INDEX ' + q(index.name) + ' ON ' + q(table) + ' (' + index.columns.map(q).join(',') + ')');
            for (const key of structure.foreign) foreign.push('ALTER TABLE ' + q(table) + ' ADD FOREIGN KEY (' + key.columns.map(q).join(',') + ') REFERENCES ' + q(key.foreign_table) + ' (' + key.foreign_columns.map(q).join(',') + ') ON DELETE ' + key.on_delete.toUpperCase() + ' ON UPDATE ' + key.on_update.toUpperCase());
        }
        return [...ddl, ...indexes, ...foreign].join(';\n') + ';';
    }
    async function fixture(existing) {
        const db = existing || new PGlite();
        if (existing) await db.exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
        await execute(db, baseline());
        await db.exec(`INSERT INTO tenants(id,name) VALUES(1,'Shop');
            INSERT INTO branches(id,tenant_id,name) VALUES(1,1,'Main');
            INSERT INTO users(id,name,email,password,tenant_id,branch_id) VALUES(1,'Employee','employee@test.invalid','not-a-real-password',1,1);
            INSERT INTO employees(id,user_id,tenant_id,branch_id,employee_code,first_name,last_name,hire_date,basic_salary) VALUES(1,1,1,1,'E1','First','Last','2026-01-01',3100);
            INSERT INTO products(id,tenant_id,name,product_number) VALUES(1,1,'Product','P1');
            INSERT INTO branch_products(id,tenant_id,branch_id,product_id,stock_quantity) VALUES(1,1,1,1,10);
            INSERT INTO employee_attendances(id,employee_id,date,check_in,check_out) VALUES(1,1,'2026-10-01','22:00','06:00');
            INSERT INTO payroll_periods(id,title,start_date,end_date) VALUES(1,'October','2026-10-01','2026-10-31');
            INSERT INTO employee_payrolls(id,payroll_period_id,employee_id,basic_salary,net_salary) VALUES(1,1,1,3100,3100);
            INSERT INTO orders(id,tenant_id,branch_id,invoice_number,type,subtotal,total) VALUES(1,1,1,'SALE-1','pos',10,10);`);
        return db;
    }
    (async () => {
        const db = await fixture();
        await execute(db, upgrade);
        assert.equal((await db.query('SELECT tenant_id FROM payroll_periods WHERE id=1')).rows[0].tenant_id, 1);
        assert.equal((await db.query('SELECT worked_minutes FROM employee_attendances WHERE id=1')).rows[0].worked_minutes, 480);
        assert.equal((await db.query('SELECT count(*)::integer AS n FROM employee_salary_histories')).rows[0].n, 1);
        assert.equal((await db.query('SELECT source FROM orders WHERE id=1')).rows[0].source, 'pos');
        await db.exec("UPDATE branch_products SET stock_quantity=-2 WHERE id=1");
        assert.equal((await db.query('SELECT quantity::float FROM stock_movements ORDER BY id DESC LIMIT 1')).rows[0].quantity, -12);
        await assert.rejects(() => db.exec('DELETE FROM stock_movements'), /immutable/);
        await assert.rejects(() => db.exec('DELETE FROM employees WHERE id=1'), /foreign key/);
        await db.exec("UPDATE payroll_periods SET status='approved' WHERE id=1");
        await assert.rejects(() => db.exec('DELETE FROM employee_payrolls WHERE id=1'), /Approved payroll/);
        await execute(db, upgrade);
        assert.equal((await db.query('SELECT count(*)::integer AS n FROM stock_movements')).rows[0].n, 2);
        assert.equal((await db.query('SELECT count(*)::integer AS n FROM employee_attendance_sessions')).rows[0].n, 1);
        await db.exec("INSERT INTO tenants(id,name) VALUES(2,'Other'); INSERT INTO categories(id,tenant_id,name) VALUES(2,2,'Foreign')");
        await assert.rejects(() => db.exec('UPDATE products SET category_id=2 WHERE id=1'), /Cross-tenant/);
        await assert.rejects(() => db.exec('UPDATE products SET tenant_id=2 WHERE id=1'), /reassign/);
        await assert.rejects(() => db.exec('UPDATE products SET cost_price=-1 WHERE id=1'), /check constraint/);
        await db.exec("INSERT INTO employees(id,tenant_id,employee_code,first_name,last_name,hire_date) VALUES(2,2,'E1','Other','Employee','2026-01-01')");
        await assert.rejects(() => db.exec("INSERT INTO employees(id,tenant_id,employee_code,first_name,last_name,hire_date) VALUES(3,2,'E1','Duplicate','Employee','2026-01-01')"), /unique constraint/);
        const duplicate = await fixture(db);
        await duplicate.exec("INSERT INTO products(id,tenant_id,name,product_number) VALUES(2,1,'Duplicate','P1')");
        await assert.rejects(() => duplicate.exec(upgrade), /unique index/);
        await duplicate.exec('ROLLBACK');
        assert.equal((await duplicate.query("SELECT count(*)::integer AS n FROM information_schema.columns WHERE table_name='orders' AND column_name='source'")).rows[0].n, 0);
        assert.equal((await duplicate.query('SELECT count(*)::integer AS n FROM products')).rows[0].n, 2);
        const ambiguous = await fixture(db);
        await ambiguous.exec("INSERT INTO tenants(id,name) VALUES(2,'Second'); INSERT INTO payroll_periods(id,title,start_date,end_date) VALUES(2,'Unassigned','2026-09-01','2026-09-30')");
        await assert.rejects(() => ambiguous.exec(upgrade), /pos_payroll_tenant_map/);
        await ambiguous.exec('ROLLBACK');
        const mapped = upgrade.replace('-- INSERT INTO pos_payroll_tenant_map VALUES (<period_id>, <tenant_id>);', 'INSERT INTO pos_payroll_tenant_map VALUES (2,2);');
        await execute(ambiguous, mapped);
        assert.equal((await ambiguous.query('SELECT tenant_id FROM payroll_periods WHERE id=2')).rows[0].tenant_id, 2);
        const wrongEmployee = await fixture(db);
        await wrongEmployee.exec("INSERT INTO employees(id,tenant_id,employee_code,first_name,last_name,hire_date) VALUES(2,1,'E2','Other','Employee','2026-01-01'); INSERT INTO employee_payrolls(id,payroll_period_id,employee_id,basic_salary,net_salary) VALUES(2,1,2,100,100); INSERT INTO employee_payments(employee_id,employee_payroll_id,payment_type,amount,payment_date) VALUES(1,2,'payroll',10,'2026-10-01')");
        await assert.rejects(() => wrongEmployee.exec(upgrade), /Different employee/);
        await wrongEmployee.exec('ROLLBACK');
        assert.equal((await wrongEmployee.query('SELECT count(*)::integer AS n FROM employee_payments')).rows[0].n, 1);
        await wrongEmployee.close();
        console.log('POSTGRES_UPGRADE_OK');
    })().catch(error => { console.error(error.message, error.detail || '', error.where || ''); process.exitCode = 1; });
    JS;
    $scriptFile = sys_get_temp_dir().'/wasla-pg-upgrade-'.bin2hex(random_bytes(8)).'.cjs';
    file_put_contents($scriptFile, $script);
    $process = new Process(['node', $scriptFile, $module, database_path('upgrade_2026_10_28_postgresql.sql')], base_path());
    $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR));
    $process->setTimeout(120);
    try {
        $process->run();
    } finally {
        unlink($scriptFile);
    }
    if (! $process->isSuccessful()) {
        throw new RuntimeException('PostgreSQL integration exit '.$process->getExitCode().': '.$process->getErrorOutput().$process->getOutput());
    }
    expect($process->getOutput())->toContain('POSTGRES_UPGRADE_OK');
});
