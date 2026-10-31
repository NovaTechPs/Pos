<?php

namespace App\Services;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SchemaIntegrity extends Schema
{
    /** Execute internally generated trigger DDL without MySQL prepared statements. */
    private static function executeTrigger(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }

    /** @var array<string, array<string, string>> */
    private const CHECKS = [
        'employees' => ['salary' => 'basic_salary >= 0 AND hourly_rate >= 0', 'termination' => 'terminated_at IS NULL OR terminated_at >= hire_date'],
        'employee_salary_histories' => ['rates' => 'basic_salary >= 0 AND hourly_rate >= 0', 'dates' => 'effective_until IS NULL OR effective_until >= effective_from'],
        'employee_shifts' => ['grace' => 'grace_period_minutes >= 0', 'dates' => 'effective_until IS NULL OR effective_until >= effective_from'],
        'employee_attendances' => ['minutes' => 'late_minutes >= 0 AND overtime_minutes >= 0 AND worked_minutes >= 0 AND overtime_approved_minutes >= 0 AND overtime_approved_minutes <= overtime_minutes AND production_units >= 0'],
        'employee_attendance_sessions' => ['dates' => 'checked_out_at IS NULL OR checked_out_at > checked_in_at'],
        'employee_departures' => ['minutes' => 'minutes > 0 AND minutes <= 1440', 'times' => 'end_time > start_time OR ends_next_day = TRUE'],
        'employee_leaves' => ['dates' => 'end_date >= start_date AND total_days > 0'],
        'payroll_periods' => ['dates' => 'end_date >= start_date'],
        'employee_advances' => ['amounts' => 'amount > 0 AND installment_amount > 0 AND paid_amount >= 0 AND paid_amount <= amount'],
        'employee_bonuses' => ['amount' => 'amount >= 0'],
        'employee_deductions' => ['amount' => 'amount >= 0'],
        'employee_payrolls' => ['amounts' => 'basic_salary >= 0 AND total_allowances >= 0 AND total_deductions >= 0 AND advance_deduction >= 0 AND net_salary >= 0 AND paid_amount >= 0'],
        'employee_payments' => ['amount' => 'amount > 0'],
        'employee_payroll_items' => ['amount' => 'amount >= 0'],
        'employee_payroll_carryovers' => ['amounts' => 'amount >= 0 AND applied_amount >= 0 AND remaining_amount >= 0 AND applied_amount + remaining_amount <= amount + 0.01'],
        'employee_payroll_carryover_applications' => ['amount' => 'amount > 0'],
        'payments' => ['amount' => 'amount > 0'],
        'orders' => ['paid' => "paid_amount >= 0 OR type = 'return'", 'discount' => "(discount >= 0 OR type = 'return') AND delivery_fee >= 0"],
        'purchases' => ['amounts' => 'total >= 0 AND paid_amount >= 0'],
        'products' => ['price' => 'cost_price >= 0 AND (website_price IS NULL OR website_price >= 0)'],
        'branch_products' => ['prices' => 'retail_price >= 0 AND wholesale_price >= 0 AND (offer_price IS NULL OR offer_price >= 0)'],
        'order_items' => ['line' => 'quantity <> 0 AND unit_price >= 0 AND cost_price >= 0'],
        'purchase_items' => ['line' => 'quantity > 0 AND unit_cost >= 0'],
        'purchase_returns' => ['total' => 'total >= 0'],
        'purchase_return_items' => ['line' => 'quantity > 0 AND unit_cost >= 0 AND subtotal >= 0'],
        'stock_transfer_items' => ['quantity' => 'quantity > 0'],
        'stock_movements' => ['balance' => 'quantity <> 0 AND ABS(quantity_after - quantity_before - quantity) < 0.001'],
    ];

    /**
     * Compile the normal Laravel schema and attach native CHECK constraints to
     * CREATE TABLE, including SQLite which cannot add them using ALTER TABLE.
     *
     * @param  Closure(Blueprint): void  $definition
     */
    public static function create(string $table, Closure $definition): void
    {
        $connection = DB::connection();
        $queries = $connection->pretend(fn () => Schema::create($table, $definition));
        foreach ($queries as $query) {
            $sql = $query['query'];
            if (preg_match('/^create table /i', $sql)) {
                $position = strrpos($sql, ')');
                if ($position === false) {
                    throw new RuntimeException('Invalid CREATE TABLE statement.');
                }
                $constraints = '';
                foreach (self::CHECKS[$table] ?? [] as $name => $expression) {
                    $identifier = $connection->getQueryGrammar()->wrap($table.'_'.$name.'_check');
                    $constraints .= ', CONSTRAINT '.$identifier.' CHECK ('.$expression.')';
                }
                $sql = substr($sql, 0, $position).$constraints.substr($sql, $position);
            }
            $connection->statement($sql, $query['bindings']);
        }
    }

    public static function finalize(): void
    {
        self::dropTenantGuards();
        foreach (Schema::getTables() as $table) {
            self::tenantGuards($table['name']);
        }
        self::protectApprovedPayrolls();
    }

    private static function protectApprovedPayrolls(): void
    {
        $connection = DB::connection();
        $name = 'approved_payroll_delete';
        $condition = "OLD.paid_amount > 0 OR EXISTS (SELECT 1 FROM payroll_periods WHERE id = OLD.payroll_period_id AND status IN ('approved', 'paid'))";
        if ($connection->getDriverName() === 'pgsql') {
            self::executeTrigger('DROP TRIGGER IF EXISTS '.$name.' ON employee_payrolls');
            self::executeTrigger('CREATE OR REPLACE FUNCTION '.$name.'_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF '.$condition." THEN RAISE EXCEPTION 'Approved payroll cannot be deleted'; END IF; RETURN OLD; END; $$");
            self::executeTrigger('CREATE TRIGGER '.$name.' BEFORE DELETE ON employee_payrolls FOR EACH ROW EXECUTE FUNCTION '.$name.'_fn()');
        } else {
            self::executeTrigger('DROP TRIGGER IF EXISTS '.$name);
            $sql = $connection->getDriverName() === 'sqlite'
                ? 'CREATE TRIGGER '.$name.' BEFORE DELETE ON employee_payrolls FOR EACH ROW WHEN '.$condition." BEGIN SELECT RAISE(ABORT, 'Approved payroll cannot be deleted'); END"
                : 'CREATE TRIGGER '.$name.' BEFORE DELETE ON employee_payrolls FOR EACH ROW BEGIN IF '.$condition." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved payroll cannot be deleted'; END IF; END";
            self::executeTrigger($sql);
        }
    }

    public static function dropTenantGuards(): void
    {
        foreach (Schema::getTables() as $table) {
            foreach (['insert', 'update'] as $event) {
                $name = substr($table['name'], 0, 36).'_tenant_'.$event;
                if (DB::connection()->getDriverName() === 'pgsql') {
                    self::executeTrigger('DROP TRIGGER IF EXISTS '.$name.' ON '.$table['name']);
                    self::executeTrigger('DROP FUNCTION IF EXISTS '.$name.'_fn()');
                } else {
                    self::executeTrigger('DROP TRIGGER IF EXISTS '.$name);
                }
            }
        }
    }

    private static function ownerExpression(string $table, string $row, int $depth = 0): ?string
    {
        if (Schema::hasColumn($table, 'tenant_id')) {
            return $row.'.tenant_id';
        }
        $anchors = ['purchase_items' => ['purchase_id', 'purchases'], 'employee_payroll_items' => ['employee_payroll_id', 'employee_payrolls'],
            'employee_payroll_carryover_applications' => ['employee_payroll_id', 'employee_payrolls']];
        if (str_starts_with($table, 'employee_') && Schema::hasColumn($table, 'employee_id')) {
            $anchors[$table] = ['employee_id', 'employees'];
        }
        if (! isset($anchors[$table]) || $depth > 3) {
            return null;
        }
        [$column, $parent] = $anchors[$table];
        $alias = 'owner_'.$depth;
        $owner = self::ownerExpression($parent, $alias, $depth + 1);

        return $owner ? '(SELECT '.$owner.' FROM '.$parent.' '.$alias.' WHERE '.$alias.'.id = '.$row.'.'.$column.')' : null;
    }

    private static function tenantGuards(string $table): void
    {
        $owner = self::ownerExpression($table, 'NEW');
        if (! $owner) {
            return;
        }
        $businessColumns = ['branch_id', 'from_branch_id', 'to_branch_id', 'product_id', 'category_id', 'customer_id', 'supplier_id', 'party_id',
            'order_id', 'original_order_id', 'original_order_item_id', 'purchase_id', 'purchase_item_id', 'purchase_return_id', 'stock_transfer_id',
            'branch_product_id', 'device_id', 'role_id', 'payroll_period_id', 'target_period_id', 'employee_payroll_id', 'employee_advance_id',
            'source_payroll_id', 'employee_payroll_carryover_id', 'employee_attendance_id', 'user_id'];
        $conditions = [];
        foreach (Schema::getForeignKeys($table) as $key) {
            if (count($key['columns']) !== 1) {
                continue;
            }
            $column = $key['columns'][0];
            $parent = $key['foreign_table'];
            if (! in_array($column, $businessColumns, true) || ! Schema::hasTable($parent)) {
                continue;
            }
            $parentOwner = self::ownerExpression($parent, 'linked');
            if ($parentOwner) {
                $conditions[] = '('.$owner.' IS NOT NULL AND NEW.'.$column.' IS NOT NULL AND NOT EXISTS (SELECT 1 FROM '.$parent.' linked WHERE linked.'.$key['foreign_columns'][0].' = NEW.'.$column.' AND '.$parentOwner.' = '.$owner.'))';
            }
            if (str_starts_with($table, 'employee_') && Schema::hasColumn($table, 'employee_id') && Schema::hasColumn($parent, 'employee_id')) {
                $conditions[] = '(NEW.'.$column.' IS NOT NULL AND NOT EXISTS (SELECT 1 FROM '.$parent.' linked WHERE linked.id = NEW.'.$column.' AND linked.employee_id = NEW.employee_id))';
            }
            if ($table === 'employee_payroll_items' && Schema::hasColumn($parent, 'employee_id')) {
                $conditions[] = '(NEW.'.$column.' IS NOT NULL AND NOT EXISTS (SELECT 1 FROM '.$parent.' linked JOIN employee_payrolls payroll ON payroll.id = NEW.employee_payroll_id WHERE linked.id = NEW.'.$column.' AND linked.employee_id = payroll.employee_id))';
            }
        }
        $hasTenant = Schema::hasColumn($table, 'tenant_id');
        if ($conditions === [] && ! $hasTenant) {
            return;
        }
        foreach (['insert', 'update'] as $event) {
            $eventConditions = $conditions;
            if ($event === 'update' && $hasTenant) {
                $eventConditions[] = '(OLD.tenant_id IS NOT NULL AND (NEW.tenant_id IS NULL OR NEW.tenant_id <> OLD.tenant_id))';
            }
            if ($eventConditions === []) {
                continue;
            }
            $condition = implode(' OR ', $eventConditions);
            $name = substr($table, 0, 36).'_tenant_'.$event;
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                self::executeTrigger('CREATE TRIGGER '.$name.' BEFORE '.strtoupper($event).' ON '.$table.' FOR EACH ROW WHEN '.$condition." BEGIN SELECT RAISE(ABORT, 'Cross-tenant or employee relationship'); END");
            } elseif ($driver === 'pgsql') {
                self::executeTrigger('CREATE OR REPLACE FUNCTION '.$name.'_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF '.$condition." THEN RAISE EXCEPTION 'Cross-tenant or employee relationship'; END IF; RETURN NEW; END; $$");
                self::executeTrigger('CREATE TRIGGER '.$name.' BEFORE '.strtoupper($event).' ON '.$table.' FOR EACH ROW EXECUTE FUNCTION '.$name.'_fn()');
            } else {
                self::executeTrigger('CREATE TRIGGER '.$name.' BEFORE '.strtoupper($event).' ON '.$table.' FOR EACH ROW BEGIN IF '.$condition." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cross-tenant or employee relationship'; END IF; END");
            }
        }
    }

    public static function stockLedger(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $changed = $driver === 'pgsql'
            ? 'NEW.stock_operation_uuid IS DISTINCT FROM OLD.stock_operation_uuid'
            : "COALESCE(NEW.stock_operation_uuid, '') <> COALESCE(OLD.stock_operation_uuid, '')";
        $fields = 'tenant_id, branch_id, product_id, branch_product_id, quantity_before, quantity_after, quantity, source_type, source_id, source_reference, operation_uuid, recorded_by, created_at';
        $insert = "INSERT INTO stock_movements ({$fields}) SELECT NEW.tenant_id, NEW.branch_id, NEW.product_id, NEW.id, 0, NEW.stock_quantity, NEW.stock_quantity, COALESCE(NEW.stock_source_type, 'opening'), COALESCE(NEW.stock_source_id, NEW.id), NEW.stock_source_reference, NEW.stock_operation_uuid, NEW.stock_actor_id, CURRENT_TIMESTAMP WHERE NEW.stock_quantity <> 0";
        $update = "INSERT INTO stock_movements ({$fields}) SELECT NEW.tenant_id, NEW.branch_id, NEW.product_id, NEW.id, OLD.stock_quantity, NEW.stock_quantity, NEW.stock_quantity - OLD.stock_quantity, CASE WHEN {$changed} THEN COALESCE(NEW.stock_source_type, 'manual') ELSE 'manual' END, CASE WHEN {$changed} THEN NEW.stock_source_id ELSE NULL END, CASE WHEN {$changed} THEN NEW.stock_source_reference ELSE NULL END, CASE WHEN {$changed} THEN NEW.stock_operation_uuid ELSE NULL END, CASE WHEN {$changed} THEN NEW.stock_actor_id ELSE NULL END, CURRENT_TIMESTAMP WHERE NEW.stock_quantity <> OLD.stock_quantity";
        foreach (['insert' => $insert, 'update' => $update] as $event => $body) {
            $name = 'stock_ledger_'.$event;
            if ($driver === 'pgsql') {
                self::executeTrigger('CREATE OR REPLACE FUNCTION '.$name.'_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '.$body.'; RETURN NEW; END; $$');
                self::executeTrigger('DROP TRIGGER IF EXISTS '.$name.' ON branch_products');
                self::executeTrigger('CREATE TRIGGER '.$name.' AFTER '.strtoupper($event).' ON branch_products FOR EACH ROW EXECUTE FUNCTION '.$name.'_fn()');
            } elseif ($driver === 'sqlite') {
                self::executeTrigger('CREATE TRIGGER '.$name.' AFTER '.strtoupper($event).' ON branch_products FOR EACH ROW BEGIN '.$body.'; END');
            } else {
                self::executeTrigger('CREATE TRIGGER '.$name.' AFTER '.strtoupper($event).' ON branch_products FOR EACH ROW '.$body);
            }
        }
        foreach (['update', 'delete'] as $event) {
            $name = 'stock_ledger_no_'.$event;
            if ($driver === 'pgsql') {
                self::executeTrigger("CREATE OR REPLACE FUNCTION {$name}_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Stock movements are immutable'; END; $$");
                self::executeTrigger('DROP TRIGGER IF EXISTS '.$name.' ON stock_movements');
                self::executeTrigger('CREATE TRIGGER '.$name.' BEFORE '.strtoupper($event).' ON stock_movements FOR EACH ROW EXECUTE FUNCTION '.$name.'_fn()');
            } elseif ($driver === 'sqlite') {
                self::executeTrigger('CREATE TRIGGER '.$name.' BEFORE '.strtoupper($event)." ON stock_movements BEGIN SELECT RAISE(ABORT, 'Stock movements are immutable'); END");
            } else {
                self::executeTrigger('CREATE TRIGGER '.$name.' BEFORE '.strtoupper($event)." ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock movements are immutable'");
            }
        }
    }

    public static function dropStockLedger(): void
    {
        foreach (['insert', 'update', 'no_update', 'no_delete'] as $event) {
            $name = 'stock_ledger_'.$event;
            if (DB::connection()->getDriverName() === 'pgsql') {
                self::executeTrigger('DROP TRIGGER IF EXISTS '.$name.' ON '.(str_starts_with($event, 'no_') ? 'stock_movements' : 'branch_products'));
                self::executeTrigger('DROP FUNCTION IF EXISTS '.$name.'_fn()');
            } else {
                self::executeTrigger('DROP TRIGGER IF EXISTS '.$name);
            }
        }
    }
}
