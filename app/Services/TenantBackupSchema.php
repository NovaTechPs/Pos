<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class TenantBackupSchema
{
    private const EXCLUDED = ['tenants', 'sessions', 'password_reset_tokens', 'personal_access_tokens', 'passkeys'];

    /** @return array<string, array<string, mixed>> */
    public function tables(): array
    {
        $all = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, self::EXCLUDED, true)) {
                continue;
            }
            $columns = Schema::getColumns($name);
            $all[$name] = ['columns' => array_column($columns, 'name'), 'nullable' => array_column(array_filter($columns, fn ($column) => $column['nullable']), 'name'),
                'types' => array_column($columns, 'type', 'name'), 'keys' => Schema::getForeignKeys($name),
                'primary' => collect(Schema::getIndexes($name))->firstWhere('primary', true)['columns'] ?? []];
        }
        $owned = array_filter($all, fn ($table) => in_array('tenant_id', $table['columns'], true));
        do {
            $changed = false;
            foreach (array_diff_key($all, $owned) as $name => $table) {
                $anchors = ['purchase_items' => 'purchase_id', 'employee_payroll_items' => 'employee_payroll_id',
                    'employee_payroll_carryover_applications' => 'employee_payroll_id', 'permission_role' => 'role_id'];
                $preferred = $anchors[$name] ?? (str_starts_with($name, 'employee_') && in_array('employee_id', $table['columns'], true) ? 'employee_id' : null);
                foreach ($table['keys'] as $key) {
                    if (($preferred !== null && $key['columns'] !== [$preferred]) || ($preferred === null && array_intersect($key['columns'], $table['nullable']) !== [])) {
                        continue;
                    }

                    if (isset($owned[$key['foreign_table']]) && count($key['columns']) === 1) {
                        $owned[$name] = $table + ['anchor' => $key];
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
        ksort($owned);
        foreach ($owned as $table) {
            if ($table['primary'] === []) {
                throw new RuntimeException('جدول تابع للمتجر بلا مفتاح أساسي؛ يلزم تحديث نظام النسخ.');
            }
        }

        return $owned;
    }

    /** @param array<string, array<string, mixed>> $tables */
    public function scope(string $table, int $tenantId, array $tables): Builder
    {
        $query = DB::table($table);
        if (in_array('tenant_id', $tables[$table]['columns'], true)) {
            return $query->where($table.'.tenant_id', $tenantId);
        }
        $key = $tables[$table]['anchor'];

        return $query->whereIn($table.'.'.$key['columns'][0], $this->scope($key['foreign_table'], $tenantId, $tables)->select($key['foreign_table'].'.'.$key['foreign_columns'][0]));
    }

    /** @param array<string, array<string, mixed>> $tables
     * @return list<string>
     */
    public function order(array $tables): array
    {
        $order = [];
        while (count($order) < count($tables)) {
            $progress = false;
            foreach (array_diff(array_keys($tables), $order) as $name) {
                $dependencies = [];
                foreach ($tables[$name]['keys'] as $key) {
                    if (isset($tables[$key['foreign_table']]) && array_intersect($key['columns'], $tables[$name]['nullable']) === []) {
                        $dependencies[] = $key['foreign_table'];
                    }
                }
                if (array_diff($dependencies, $order) === []) {
                    $order[] = $name;
                    $progress = true;
                }
            }
            if (! $progress) {
                throw new RuntimeException('تعذر ترتيب علاقات الجداول بأمان.');
            }
        }

        return $order;
    }

    /** Guards are suspended only inside a database supporting transactional DDL.
     * @return list<string>
     */
    public function suspendGuards(): array
    {
        $definitions = [];
        $targets = ['stock_ledger_insert' => 'branch_products', 'stock_ledger_update' => 'branch_products',
            'stock_ledger_no_update' => 'stock_movements', 'stock_ledger_no_delete' => 'stock_movements', 'approved_payroll_delete' => 'employee_payrolls'];
        foreach ($targets as $name => $table) {
            if (DB::getDriverName() === 'pgsql') {
                $row = DB::selectOne('SELECT pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE t.tgname=? AND c.relname=? AND n.nspname=current_schema()', [$name, $table]);
                if ($row) {
                    $definitions[] = $row->definition;
                    DB::statement('DROP TRIGGER '.$name.' ON '.$table);
                }
            } else {
                $row = DB::selectOne("SELECT sql AS definition FROM sqlite_master WHERE type='trigger' AND name=?", [$name]);
                if ($row) {
                    $definitions[] = $row->definition;
                    DB::statement('DROP TRIGGER '.$name);
                }
            }
        }

        return $definitions;
    }

    /** Refuse legacy cross-store references instead of cascading into another store.
     * @param  array<string, array<string, mixed>>  $tables
     */
    public function assertNoExternalReferences(int $tenantId, array $tables): void
    {
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ($name === 'passkeys') {
                continue;
            }
            foreach (Schema::getForeignKeys($name) as $key) {
                if (! isset($tables[$key['foreign_table']]) || ! in_array('id', $key['foreign_columns'], true)) {
                    continue;
                }
                $column = $key['columns'][array_search('id', $key['foreign_columns'], true)];
                $query = DB::table($name)->whereIn($column, $this->scope($key['foreign_table'], $tenantId, $tables)->select('id'));
                if ($name === 'tenants') {
                    $query->where('id', '!=', $tenantId);
                } elseif (isset($tables[$name])) {
                    $primary = $tables[$name]['primary'];
                    if (count($primary) !== 1) {
                        continue;
                    }
                    $query->whereNotIn($primary[0], $this->scope($name, $tenantId, $tables)->select($primary[0]));
                }
                if ($query->exists()) {
                    throw new RuntimeException('توجد علاقة خارج المتجر تمنع الاستعادة الآمنة: '.$name);
                }
            }
        }
    }
}
