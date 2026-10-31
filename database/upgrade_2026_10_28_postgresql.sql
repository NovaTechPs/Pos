-- WaslaPOS: upgrade an existing PostgreSQL database to the 2026-10-28 schema.
-- Back up the database first. Run the ENTIRE file during a maintenance window.
-- Intended schema: public. Requires the application table owner, PostgreSQL 14+.
-- No application rows are deleted. Any error rolls back this whole transaction.
-- Historical salary rates cannot be reconstructed: current rates become the initial snapshot.
-- Stock history begins with an opening balance; past document movements are not invented.
BEGIN;
SET LOCAL search_path = public, pg_temp;
SET LOCAL lock_timeout = '10s';
SET LOCAL statement_timeout = '10min';
SELECT pg_advisory_xact_lock(20261028, 1);

-- Optional explicit mapping for old payroll periods with no unambiguous tenant.
CREATE TEMP TABLE pos_payroll_tenant_map (period_id bigint PRIMARY KEY, tenant_id bigint NOT NULL) ON COMMIT DROP;
-- INSERT INTO pos_payroll_tenant_map VALUES (<period_id>, <tenant_id>);

CREATE OR REPLACE FUNCTION pg_temp.pos_constraint(t text, n text, definition text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass('public.' || t) AND conname = n) THEN
        EXECUTE format('ALTER TABLE public.%I ADD CONSTRAINT %I %s', t, n, definition);
    END IF;
END $$;

CREATE OR REPLACE FUNCTION pg_temp.pos_fk(t text, cols text[], parent text, pcols text[], action text, n text) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r record; keys smallint[]; names text; pnames text;
BEGIN
    SELECT array_agg(a.attnum ORDER BY c.ord), string_agg(format('%I', c.col), ', ' ORDER BY c.ord)
      INTO keys, names FROM unnest(cols) WITH ORDINALITY c(col, ord)
      JOIN pg_attribute a ON a.attrelid = to_regclass('public.' || t) AND a.attname = c.col AND NOT a.attisdropped;
    SELECT string_agg(format('%I', col), ', ' ORDER BY ord) INTO pnames FROM unnest(pcols) WITH ORDINALITY c(col, ord);
    IF cardinality(keys) IS DISTINCT FROM cardinality(cols) THEN RAISE EXCEPTION 'Missing FK columns on %', t; END IF;
    FOR r IN SELECT conname, confrelid, confdeltype FROM pg_constraint WHERE conrelid = to_regclass('public.' || t) AND contype = 'f' AND conkey = keys LOOP
        IF r.confrelid <> to_regclass('public.' || parent) THEN RAISE EXCEPTION 'Unexpected FK target on %.%', t, names; END IF;
        IF r.conname = n AND r.confdeltype = (CASE action WHEN 'RESTRICT' THEN 'r'::"char" WHEN 'CASCADE' THEN 'c'::"char" WHEN 'SET NULL' THEN 'n'::"char" END) THEN RETURN; END IF;
        EXECUTE format('ALTER TABLE public.%I DROP CONSTRAINT %I', t, r.conname);
    END LOOP;
    IF action NOT IN ('RESTRICT', 'CASCADE', 'SET NULL') THEN RAISE EXCEPTION 'Invalid FK action'; END IF;
    EXECUTE format('ALTER TABLE public.%I ADD CONSTRAINT %I FOREIGN KEY (%s) REFERENCES public.%I (%s) ON DELETE %s', t, n, names, parent, pnames, action);
END $$;

-- Required columns, including the immediate sales synchronization fix.
ALTER TABLE orders ADD COLUMN IF NOT EXISTS source varchar(255);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS sale_kind varchar(255);
CREATE TEMP TABLE pos_unclassified_orders ON COMMIT DROP AS SELECT id, source IS NULL AS needs_source, sale_kind IS NULL AS needs_kind FROM orders WHERE source IS NULL OR sale_kind IS NULL;
UPDATE orders SET source = CASE WHEN type = 'online' THEN 'online' WHEN type = 'pos' OR shift_id IS NOT NULL THEN 'pos' ELSE 'web' END WHERE source IS NULL;
UPDATE orders SET sale_kind = CASE WHEN type = 'wholesale' AND source <> 'pos' THEN 'wholesale' ELSE 'retail' END WHERE sale_kind IS NULL;
UPDATE orders r SET source = CASE WHEN u.needs_source THEN o.source ELSE r.source END,
    sale_kind = CASE WHEN u.needs_kind THEN o.sale_kind ELSE r.sale_kind END
FROM orders o, pos_unclassified_orders u WHERE r.id = u.id AND r.type = 'return' AND r.original_order_id = o.id;
-- Unlabelled legacy wholesale invoices cannot always distinguish POS credit sales
-- from actual wholesale sales. Review those historical classifications separately.
ALTER TABLE orders ALTER COLUMN source SET DEFAULT 'web', ALTER COLUMN source SET NOT NULL,
    ALTER COLUMN sale_kind SET DEFAULT 'retail', ALTER COLUMN sale_kind SET NOT NULL;
SELECT pg_temp.pos_constraint('orders', 'orders_source_check', $$CHECK (source IN ('pos','web','api','online','sync'))$$);
SELECT pg_temp.pos_constraint('orders', 'orders_sale_kind_check', $$CHECK (sale_kind IN ('retail','wholesale'))$$);
CREATE INDEX IF NOT EXISTS orders_source_kind_date_index ON orders (tenant_id, source, sale_kind, created_at);

ALTER TABLE employees ADD COLUMN IF NOT EXISTS tenant_id bigint;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS branch_id bigint;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS terminated_at date;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS termination_reason text;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS terminated_by bigint;
UPDATE employees e SET tenant_id = u.tenant_id FROM users u WHERE e.user_id = u.id AND e.tenant_id IS NULL;
UPDATE employees e SET branch_id = u.branch_id FROM users u WHERE e.user_id = u.id AND e.branch_id IS NULL AND u.tenant_id = e.tenant_id;
ALTER TABLE employee_shifts ADD COLUMN IF NOT EXISTS effective_from date NOT NULL DEFAULT '1970-01-01';
ALTER TABLE employee_shifts ADD COLUMN IF NOT EXISTS effective_until date;
ALTER TABLE employee_attendances ADD COLUMN IF NOT EXISTS worked_minutes integer NOT NULL DEFAULT 0;
ALTER TABLE employee_attendances ADD COLUMN IF NOT EXISTS overtime_approved_minutes integer NOT NULL DEFAULT 0;
ALTER TABLE employee_attendances ADD COLUMN IF NOT EXISTS production_units numeric(12,3) NOT NULL DEFAULT 0;
ALTER TABLE employee_departures ADD COLUMN IF NOT EXISTS ends_next_day boolean NOT NULL DEFAULT false;
ALTER TABLE employee_departures ADD COLUMN IF NOT EXISTS approved_by bigint;
ALTER TABLE employee_departures ADD COLUMN IF NOT EXISTS approved_at timestamp(0) without time zone;
ALTER TABLE employee_payrolls ADD COLUMN IF NOT EXISTS earnings_breakdown json;
ALTER TABLE payroll_periods ADD COLUMN IF NOT EXISTS tenant_id bigint;
ALTER TABLE employee_payroll_items ADD COLUMN IF NOT EXISTS employee_advance_id bigint;
ALTER TABLE employee_payroll_items ADD COLUMN IF NOT EXISTS employee_payroll_carryover_id bigint;
ALTER TABLE branch_products ADD COLUMN IF NOT EXISTS stock_source_type varchar(255);
ALTER TABLE branch_products ADD COLUMN IF NOT EXISTS stock_source_id bigint;
ALTER TABLE branch_products ADD COLUMN IF NOT EXISTS stock_source_reference varchar(255);
ALTER TABLE branch_products ADD COLUMN IF NOT EXISTS stock_operation_uuid uuid;
ALTER TABLE branch_products ADD COLUMN IF NOT EXISTS stock_actor_id bigint;

CREATE TABLE IF NOT EXISTS employee_salary_histories (
    id bigserial PRIMARY KEY, employee_id bigint NOT NULL REFERENCES employees(id) ON DELETE RESTRICT,
    effective_from date NOT NULL, effective_until date, salary_type varchar(255) NOT NULL,
    basic_salary numeric(12,2) NOT NULL DEFAULT 0, hourly_rate numeric(12,2) NOT NULL DEFAULT 0,
    created_by bigint REFERENCES users(id) ON DELETE RESTRICT, reason text,
    created_at timestamp(0) without time zone, updated_at timestamp(0) without time zone
);
CREATE TABLE IF NOT EXISTS employee_attendance_sessions (
    id bigserial PRIMARY KEY, employee_id bigint NOT NULL REFERENCES employees(id) ON DELETE RESTRICT,
    employee_attendance_id bigint NOT NULL REFERENCES employee_attendances(id) ON DELETE RESTRICT,
    checked_in_at timestamp(0) without time zone NOT NULL, checked_out_at timestamp(0) without time zone,
    source varchar(255) NOT NULL DEFAULT 'self', recorded_by bigint REFERENCES users(id) ON DELETE RESTRICT,
    device_name varchar(255), sync_uuid uuid NOT NULL UNIQUE,
    created_at timestamp(0) without time zone, updated_at timestamp(0) without time zone
);
CREATE TABLE IF NOT EXISTS stock_movements (
    id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT,
    branch_id bigint NOT NULL REFERENCES branches(id) ON DELETE RESTRICT,
    product_id bigint NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    branch_product_id bigint NOT NULL REFERENCES branch_products(id) ON DELETE RESTRICT,
    quantity_before numeric(15,3) NOT NULL, quantity_after numeric(15,3) NOT NULL, quantity numeric(15,3) NOT NULL,
    source_type varchar(255) NOT NULL, source_id bigint, source_reference varchar(255), operation_uuid uuid,
    recorded_by bigint REFERENCES users(id) ON DELETE RESTRICT, created_at timestamp(0) without time zone NOT NULL
);

-- Infer ownership from every existing payroll-linked document; never guess between tenants.
CREATE TEMP VIEW pos_period_owners AS
    SELECT p.payroll_period_id AS period_id, e.tenant_id FROM employee_payrolls p JOIN employees e ON e.id = p.employee_id
    UNION SELECT b.payroll_period_id, e.tenant_id FROM employee_bonuses b JOIN employees e ON e.id = b.employee_id
    UNION SELECT d.payroll_period_id, e.tenant_id FROM employee_deductions d JOIN employees e ON e.id = d.employee_id
    UNION SELECT c.target_period_id, e.tenant_id FROM employee_payroll_carryovers c JOIN employees e ON e.id = c.employee_id;
DO $$
DECLARE ids text;
BEGIN
    SELECT string_agg(period_id::text, ', ') INTO ids FROM (SELECT period_id FROM pos_period_owners WHERE period_id IS NOT NULL GROUP BY period_id HAVING count(DISTINCT tenant_id) > 1) x;
    IF ids IS NOT NULL THEN RAISE EXCEPTION 'Payroll periods belong to multiple tenants; split before upgrading. IDs: %', ids; END IF;
END $$;
UPDATE payroll_periods p SET tenant_id = o.tenant_id FROM (SELECT period_id, min(tenant_id) AS tenant_id FROM pos_period_owners GROUP BY period_id) o WHERE p.id = o.period_id AND p.tenant_id IS NULL;
UPDATE payroll_periods p SET tenant_id = m.tenant_id FROM pos_payroll_tenant_map m WHERE p.id = m.period_id AND p.tenant_id IS NULL;
UPDATE payroll_periods SET tenant_id = (SELECT min(id) FROM tenants) WHERE tenant_id IS NULL AND (SELECT count(*) FROM tenants) = 1;
DO $$
DECLARE ids text;
BEGIN
    SELECT string_agg(id::text, ', ') INTO ids FROM payroll_periods WHERE tenant_id IS NULL;
    IF ids IS NOT NULL THEN RAISE EXCEPTION 'Map these payroll periods in pos_payroll_tenant_map, then rerun: %', ids; END IF;
    SELECT string_agg(id::text, ', ') INTO ids FROM employees WHERE tenant_id IS NULL;
    IF ids IS NOT NULL THEN RAISE EXCEPTION 'Assign a tenant to these employees before upgrading: %', ids; END IF;
END $$;
ALTER TABLE payroll_periods ALTER COLUMN tenant_id SET NOT NULL;

-- Scoped uniqueness. Duplicates abort the transaction without deleting records.
CREATE UNIQUE INDEX IF NOT EXISTS products_tenant_id_product_number_unique ON products (tenant_id, product_number);
CREATE UNIQUE INDEX IF NOT EXISTS products_tenant_id_id_unique ON products (tenant_id, id);
CREATE UNIQUE INDEX IF NOT EXISTS branches_tenant_id_id_unique ON branches (tenant_id, id);
CREATE UNIQUE INDEX IF NOT EXISTS orders_tenant_id_id_unique ON orders (tenant_id, id);
CREATE UNIQUE INDEX IF NOT EXISTS employees_tenant_id_employee_code_unique ON employees (tenant_id, employee_code);
CREATE UNIQUE INDEX IF NOT EXISTS employees_tenant_id_national_id_unique ON employees (tenant_id, national_id);
CREATE UNIQUE INDEX IF NOT EXISTS employees_user_id_unique ON employees (user_id);
DO $$
DECLARE r record;
BEGIN
    FOR r IN SELECT c.conname FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        WHERE c.conrelid = 'employees'::regclass AND c.contype = 'u' AND cardinality(c.conkey) = 1 AND a.attname IN ('employee_code','national_id') LOOP
        EXECUTE format('ALTER TABLE employees DROP CONSTRAINT %I', r.conname);
    END LOOP;
    FOR r IN SELECT ci.relname FROM pg_index i JOIN pg_class ci ON ci.oid = i.indexrelid JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
        WHERE i.indrelid = 'employees'::regclass AND i.indisunique AND i.indnkeyatts = 1 AND a.attname IN ('employee_code','national_id') LOOP
        EXECUTE format('DROP INDEX %I', r.relname);
    END LOOP;
END $$;
CREATE INDEX IF NOT EXISTS employees_tenant_active_salary_index ON employees (tenant_id, is_active, salary_type);
CREATE UNIQUE INDEX IF NOT EXISTS employee_shifts_employee_id_effective_from_unique ON employee_shifts (employee_id, effective_from);
CREATE INDEX IF NOT EXISTS employee_shift_effective_index ON employee_shifts (employee_id, effective_from, effective_until);
CREATE UNIQUE INDEX IF NOT EXISTS employee_salary_histories_employee_id_effective_from_unique ON employee_salary_histories (employee_id, effective_from);
CREATE INDEX IF NOT EXISTS employee_salary_effective_index ON employee_salary_histories (employee_id, effective_from, effective_until);
CREATE INDEX IF NOT EXISTS employee_session_open_index ON employee_attendance_sessions (employee_id, checked_out_at, checked_in_at);
CREATE INDEX IF NOT EXISTS payroll_periods_tenant_dates_status_index ON payroll_periods (tenant_id, start_date, end_date, status);
CREATE INDEX IF NOT EXISTS stock_movement_lookup ON stock_movements (tenant_id, branch_id, product_id, created_at);
CREATE INDEX IF NOT EXISTS stock_movement_document_lookup ON stock_movements (tenant_id, source_type, source_id);
CREATE UNIQUE INDEX IF NOT EXISTS stock_movement_operation_unique ON stock_movements (branch_product_id, operation_uuid);

-- Native relationships, including replacement of the financial CASCADE relationships.
SELECT pg_temp.pos_fk('products', ARRAY['category_id'], 'categories', ARRAY['id'], 'SET NULL', 'products_category_id_foreign');
SELECT pg_temp.pos_fk('tenants', ARRAY['plan_id'], 'plans', ARRAY['id'], 'SET NULL', 'tenants_plan_id_foreign');
SELECT pg_temp.pos_fk('employees', ARRAY['tenant_id'], 'tenants', ARRAY['id'], 'RESTRICT', 'employees_tenant_id_foreign');
SELECT pg_temp.pos_fk('employees', ARRAY['branch_id'], 'branches', ARRAY['id'], 'SET NULL', 'employees_branch_id_foreign');
SELECT pg_temp.pos_fk('employees', ARRAY['terminated_by'], 'users', ARRAY['id'], 'RESTRICT', 'employees_terminated_by_foreign');
SELECT pg_temp.pos_fk('employee_departures', ARRAY['approved_by'], 'users', ARRAY['id'], 'RESTRICT', 'employee_departures_approved_by_foreign');
SELECT pg_temp.pos_fk('payroll_periods', ARRAY['tenant_id'], 'tenants', ARRAY['id'], 'RESTRICT', 'payroll_periods_tenant_id_foreign');
SELECT pg_temp.pos_fk('employee_payroll_items', ARRAY['employee_advance_id'], 'employee_advances', ARRAY['id'], 'RESTRICT', 'employee_payroll_items_employee_advance_id_foreign');
SELECT pg_temp.pos_fk('employee_payroll_items', ARRAY['employee_payroll_carryover_id'], 'employee_payroll_carryovers', ARRAY['id'], 'RESTRICT', 'payroll_items_carryover_foreign');
SELECT pg_temp.pos_fk('employee_payrolls', ARRAY['payroll_period_id'], 'payroll_periods', ARRAY['id'], 'RESTRICT', 'employee_payrolls_payroll_period_id_foreign');
SELECT pg_temp.pos_fk('employee_payments', ARRAY['employee_payroll_id'], 'employee_payrolls', ARRAY['id'], 'RESTRICT', 'employee_payments_employee_payroll_id_foreign');
SELECT pg_temp.pos_fk('employee_payments', ARRAY['employee_advance_id'], 'employee_advances', ARRAY['id'], 'RESTRICT', 'employee_payments_employee_advance_id_foreign');
DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['employee_advances','employee_bonuses','employee_deductions','employee_payrolls','employee_payroll_carryovers','employee_payments'] LOOP
        PERFORM pg_temp.pos_fk(t, ARRAY['employee_id'], 'employees', ARRAY['id'], 'RESTRICT', t || '_employee_id_foreign');
    END LOOP;
END $$;
SELECT pg_temp.pos_fk('branch_products', ARRAY['tenant_id','branch_id'], 'branches', ARRAY['tenant_id','id'], 'CASCADE', 'branch_products_tenant_branch_fk');
SELECT pg_temp.pos_fk('branch_products', ARRAY['tenant_id','product_id'], 'products', ARRAY['tenant_id','id'], 'CASCADE', 'branch_products_tenant_product_fk');
SELECT pg_temp.pos_fk('order_items', ARRAY['tenant_id','order_id'], 'orders', ARRAY['tenant_id','id'], 'CASCADE', 'order_items_tenant_order_fk');
SELECT pg_temp.pos_fk('order_items', ARRAY['tenant_id','product_id'], 'products', ARRAY['tenant_id','id'], 'RESTRICT', 'order_items_tenant_product_fk');

-- Initialize histories once, preserving existing dated history and approved payroll amounts.
INSERT INTO employee_salary_histories (employee_id, effective_from, salary_type, basic_salary, hourly_rate, reason, created_at, updated_at)
SELECT e.id, e.hire_date, e.salary_type, e.basic_salary, e.hourly_rate, 'Initial snapshot at schema upgrade', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM employees e WHERE NOT EXISTS (SELECT 1 FROM employee_salary_histories h WHERE h.employee_id = e.id);
INSERT INTO employee_attendance_sessions (employee_id, employee_attendance_id, checked_in_at, checked_out_at, source, sync_uuid, created_at, updated_at)
SELECT a.employee_id, a.id, a.date + a.check_in,
    CASE WHEN a.check_out IS NULL THEN NULL ELSE a.date + a.check_out + CASE WHEN a.check_out <= a.check_in THEN interval '1 day' ELSE interval '0 day' END END,
    'sync', md5('legacy-attendance:' || a.id::text)::uuid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM employee_attendances a WHERE a.check_in IS NOT NULL AND NOT EXISTS (SELECT 1 FROM employee_attendance_sessions s WHERE s.employee_attendance_id = a.id);
UPDATE employee_attendances a SET worked_minutes = x.minutes FROM (
    SELECT employee_attendance_id, floor(sum(extract(epoch FROM (checked_out_at - checked_in_at))) / 60)::integer AS minutes
    FROM employee_attendance_sessions GROUP BY employee_attendance_id HAVING bool_and(checked_out_at IS NOT NULL)
) x WHERE a.id = x.employee_attendance_id AND a.worked_minutes = 0;
INSERT INTO stock_movements (tenant_id, branch_id, product_id, branch_product_id, quantity_before, quantity_after, quantity, source_type, source_id, operation_uuid, created_at)
SELECT b.tenant_id, b.branch_id, b.product_id, b.id, 0, b.stock_quantity, b.stock_quantity, 'opening', b.id, md5('opening-stock:' || b.id::text)::uuid, CURRENT_TIMESTAMP
FROM branch_products b WHERE b.stock_quantity <> 0 AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.branch_product_id = b.id);

-- Native CHECK constraints are appended below; existing invalid data aborts the upgrade.
SELECT pg_temp.pos_constraint('employees', 'employees_salary_check', $check$CHECK (basic_salary >= 0 AND hourly_rate >= 0)$check$);
SELECT pg_temp.pos_constraint('employees', 'employees_termination_check', $check$CHECK (terminated_at IS NULL OR terminated_at >= hire_date)$check$);
SELECT pg_temp.pos_constraint('employee_salary_histories', 'employee_salary_histories_rates_check', $check$CHECK (basic_salary >= 0 AND hourly_rate >= 0)$check$);
SELECT pg_temp.pos_constraint('employee_salary_histories', 'employee_salary_histories_dates_check', $check$CHECK (effective_until IS NULL OR effective_until >= effective_from)$check$);
SELECT pg_temp.pos_constraint('employee_salary_histories', 'employee_salary_histories_salary_type_check', $check$CHECK (salary_type IN ('monthly','hourly','daily','production'))$check$);
SELECT pg_temp.pos_constraint('employee_shifts', 'employee_shifts_grace_check', $check$CHECK (grace_period_minutes >= 0)$check$);
SELECT pg_temp.pos_constraint('employee_shifts', 'employee_shifts_dates_check', $check$CHECK (effective_until IS NULL OR effective_until >= effective_from)$check$);
SELECT pg_temp.pos_constraint('employee_attendances', 'employee_attendances_minutes_check', $check$CHECK (late_minutes >= 0 AND overtime_minutes >= 0 AND worked_minutes >= 0 AND overtime_approved_minutes >= 0 AND overtime_approved_minutes <= overtime_minutes AND production_units >= 0)$check$);
SELECT pg_temp.pos_constraint('employee_attendance_sessions', 'employee_attendance_sessions_dates_check', $check$CHECK (checked_out_at IS NULL OR checked_out_at > checked_in_at)$check$);
SELECT pg_temp.pos_constraint('employee_attendance_sessions', 'employee_attendance_sessions_source_check', $check$CHECK (source IN ('self','barcode','manager','sync'))$check$);
SELECT pg_temp.pos_constraint('employee_departures', 'employee_departures_minutes_check', $check$CHECK (minutes > 0 AND minutes <= 1440)$check$);
SELECT pg_temp.pos_constraint('employee_departures', 'employee_departures_times_check', $check$CHECK (end_time > start_time OR ends_next_day IS TRUE)$check$);
SELECT pg_temp.pos_constraint('employee_leaves', 'employee_leaves_dates_check', $check$CHECK (end_date >= start_date AND total_days > 0)$check$);
SELECT pg_temp.pos_constraint('payroll_periods', 'payroll_periods_dates_check', $check$CHECK (end_date >= start_date)$check$);
SELECT pg_temp.pos_constraint('employee_advances', 'employee_advances_amounts_check', $check$CHECK (amount > 0 AND installment_amount > 0 AND paid_amount >= 0 AND paid_amount <= amount)$check$);
SELECT pg_temp.pos_constraint('employee_bonuses', 'employee_bonuses_amount_check', $check$CHECK (amount >= 0)$check$);
SELECT pg_temp.pos_constraint('employee_deductions', 'employee_deductions_amount_check', $check$CHECK (amount >= 0)$check$);
SELECT pg_temp.pos_constraint('employee_payrolls', 'employee_payrolls_amounts_check', $check$CHECK (basic_salary >= 0 AND total_allowances >= 0 AND total_deductions >= 0 AND advance_deduction >= 0 AND net_salary >= 0 AND paid_amount >= 0)$check$);
SELECT pg_temp.pos_constraint('employee_payments', 'employee_payments_amount_check', $check$CHECK (amount > 0)$check$);
SELECT pg_temp.pos_constraint('employee_payroll_items', 'employee_payroll_items_amount_check', $check$CHECK (amount >= 0)$check$);
SELECT pg_temp.pos_constraint('employee_payroll_carryovers', 'employee_payroll_carryovers_amounts_check', $check$CHECK (amount >= 0 AND applied_amount >= 0 AND remaining_amount >= 0 AND applied_amount + remaining_amount <= amount + 0.01)$check$);
SELECT pg_temp.pos_constraint('employee_payroll_carryover_applications', 'employee_payroll_carryover_applications_amount_check', $check$CHECK (amount > 0)$check$);
SELECT pg_temp.pos_constraint('payments', 'payments_amount_check', $check$CHECK (amount > 0)$check$);
SELECT pg_temp.pos_constraint('orders', 'orders_paid_check', $check$CHECK (paid_amount >= 0 OR type = 'return')$check$);
SELECT pg_temp.pos_constraint('orders', 'orders_discount_check', $check$CHECK ((discount >= 0 OR type = 'return') AND delivery_fee >= 0)$check$);
SELECT pg_temp.pos_constraint('purchases', 'purchases_amounts_check', $check$CHECK (total >= 0 AND paid_amount >= 0)$check$);
SELECT pg_temp.pos_constraint('products', 'products_price_check', $check$CHECK (cost_price >= 0 AND (website_price IS NULL OR website_price >= 0))$check$);
SELECT pg_temp.pos_constraint('branch_products', 'branch_products_prices_check', $check$CHECK (retail_price >= 0 AND wholesale_price >= 0 AND (offer_price IS NULL OR offer_price >= 0))$check$);
SELECT pg_temp.pos_constraint('order_items', 'order_items_line_check', $check$CHECK (quantity <> 0 AND unit_price >= 0 AND cost_price >= 0)$check$);
SELECT pg_temp.pos_constraint('purchase_items', 'purchase_items_line_check', $check$CHECK (quantity > 0 AND unit_cost >= 0)$check$);
SELECT pg_temp.pos_constraint('purchase_returns', 'purchase_returns_total_check', $check$CHECK (total >= 0)$check$);
SELECT pg_temp.pos_constraint('purchase_return_items', 'purchase_return_items_line_check', $check$CHECK (quantity > 0 AND unit_cost >= 0 AND subtotal >= 0)$check$);
SELECT pg_temp.pos_constraint('stock_transfer_items', 'stock_transfer_items_quantity_check', $check$CHECK (quantity > 0)$check$);
SELECT pg_temp.pos_constraint('stock_movements', 'stock_movements_balance_check', $check$CHECK (quantity <> 0 AND ABS(quantity_after - quantity_before - quantity) < 0.001)$check$);

-- End-to-end tenant and employee ownership guards, including direct SQL writes.
CREATE OR REPLACE FUNCTION pos_row_tenant(row_data jsonb) RETURNS bigint LANGUAGE plpgsql STABLE AS $$
DECLARE owner_id bigint;
BEGIN
    IF row_data ? 'tenant_id' THEN RETURN (row_data->>'tenant_id')::bigint; END IF;
    IF row_data ? 'employee_id' THEN SELECT tenant_id INTO owner_id FROM employees WHERE id = (row_data->>'employee_id')::bigint;
    ELSIF row_data ? 'employee_payroll_id' THEN SELECT e.tenant_id INTO owner_id FROM employee_payrolls p JOIN employees e ON e.id = p.employee_id WHERE p.id = (row_data->>'employee_payroll_id')::bigint;
    ELSIF row_data ? 'purchase_id' THEN SELECT tenant_id INTO owner_id FROM purchases WHERE id = (row_data->>'purchase_id')::bigint;
    END IF;
    RETURN owner_id;
END $$;
CREATE OR REPLACE FUNCTION pos_validate_row(table_oid oid, row_data jsonb, old_data jsonb DEFAULT NULL) RETURNS void LANGUAGE plpgsql AS $$
DECLARE fk record; linked jsonb; owner_id bigint; parent_owner bigint; employee_id bigint; business_columns text[] := ARRAY[
    'branch_id','from_branch_id','to_branch_id','product_id','category_id','customer_id','supplier_id','party_id','order_id','original_order_id',
    'original_order_item_id','purchase_id','purchase_item_id','purchase_return_id','stock_transfer_id','branch_product_id','device_id','role_id',
    'payroll_period_id','target_period_id','employee_payroll_id','employee_advance_id','source_payroll_id','employee_payroll_carryover_id','employee_attendance_id','user_id'];
BEGIN
    IF old_data->>'tenant_id' IS NOT NULL AND (row_data->>'tenant_id') IS DISTINCT FROM (old_data->>'tenant_id') THEN RAISE EXCEPTION 'Cannot reassign a business record to another tenant'; END IF;
    owner_id := pos_row_tenant(row_data);
    employee_id := (row_data->>'employee_id')::bigint;
    IF employee_id IS NULL AND row_data ? 'employee_payroll_id' THEN SELECT p.employee_id INTO employee_id FROM employee_payrolls p WHERE p.id = (row_data->>'employee_payroll_id')::bigint; END IF;
    FOR fk IN SELECT c.confrelid, a.attname AS col, pa.attname AS parent_col FROM pg_constraint c
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        JOIN pg_attribute pa ON pa.attrelid = c.confrelid AND pa.attnum = c.confkey[1]
        WHERE c.conrelid = table_oid AND c.contype = 'f' AND cardinality(c.conkey) = 1 AND a.attname = ANY(business_columns) LOOP
        IF row_data->>fk.col IS NULL THEN CONTINUE; END IF;
        EXECUTE format('SELECT to_jsonb(linked) FROM %s linked WHERE %I = $1::bigint', fk.confrelid::regclass, fk.parent_col) INTO linked USING row_data->>fk.col;
        parent_owner := pos_row_tenant(linked);
        IF owner_id IS NOT NULL AND linked ? 'tenant_id' AND parent_owner IS DISTINCT FROM owner_id THEN RAISE EXCEPTION 'Cross-tenant relationship on %.%', table_oid::regclass, fk.col; END IF;
        IF owner_id IS NOT NULL AND NOT (linked ? 'tenant_id') AND parent_owner IS NOT NULL AND parent_owner <> owner_id THEN RAISE EXCEPTION 'Cross-tenant relationship on %.%', table_oid::regclass, fk.col; END IF;
        IF employee_id IS NOT NULL AND linked ? 'employee_id' AND (linked->>'employee_id')::bigint IS DISTINCT FROM employee_id THEN RAISE EXCEPTION 'Different employee on %.%', table_oid::regclass, fk.col; END IF;
    END LOOP;
END $$;
CREATE OR REPLACE FUNCTION pos_tenant_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pos_validate_row(TG_RELID, to_jsonb(NEW), CASE WHEN TG_OP = 'UPDATE' THEN to_jsonb(OLD) ELSE NULL END);
    RETURN NEW;
END $$;
DO $$
DECLARE t record; row_data jsonb; old_trigger record;
BEGIN
    FOR t IN SELECT c.oid, c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r'
        AND (EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped)
             OR c.relname LIKE 'employee\_%' ESCAPE '\' OR c.relname = 'purchase_items') LOOP
        FOR row_data IN EXECUTE format('SELECT to_jsonb(x) FROM public.%I x', t.relname) LOOP PERFORM pos_validate_row(t.oid, row_data); END LOOP;
        -- Replace the earlier per-table guards with the equivalent shared implementation.
        FOR old_trigger IN SELECT tgname FROM pg_trigger WHERE tgrelid = t.oid AND NOT tgisinternal AND (tgname = left(t.relname,36) || '_tenant_insert' OR tgname = left(t.relname,36) || '_tenant_update') LOOP
            EXECUTE format('DROP TRIGGER %I ON public.%I', old_trigger.tgname, t.relname);
        END LOOP;
        EXECUTE format('DROP TRIGGER IF EXISTS pos_tenant_guard ON public.%I', t.relname);
        EXECUTE format('CREATE TRIGGER pos_tenant_guard BEFORE INSERT OR UPDATE ON public.%I FOR EACH ROW EXECUTE FUNCTION pos_tenant_guard()', t.relname);
    END LOOP;
END $$;

CREATE OR REPLACE FUNCTION stock_ledger_insert_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.stock_quantity <> 0 THEN
        INSERT INTO stock_movements (tenant_id,branch_id,product_id,branch_product_id,quantity_before,quantity_after,quantity,source_type,source_id,source_reference,operation_uuid,recorded_by,created_at)
        VALUES (NEW.tenant_id,NEW.branch_id,NEW.product_id,NEW.id,0,NEW.stock_quantity,NEW.stock_quantity,COALESCE(NEW.stock_source_type,'opening'),COALESCE(NEW.stock_source_id,NEW.id),NEW.stock_source_reference,NEW.stock_operation_uuid,NEW.stock_actor_id,CURRENT_TIMESTAMP);
    END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION stock_ledger_update_fn() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE has_context boolean := COALESCE(NEW.stock_operation_uuid::text,'') <> COALESCE(OLD.stock_operation_uuid::text,'');
BEGIN
    IF NEW.stock_quantity <> OLD.stock_quantity THEN
        INSERT INTO stock_movements (tenant_id,branch_id,product_id,branch_product_id,quantity_before,quantity_after,quantity,source_type,source_id,source_reference,operation_uuid,recorded_by,created_at)
        VALUES (NEW.tenant_id,NEW.branch_id,NEW.product_id,NEW.id,OLD.stock_quantity,NEW.stock_quantity,NEW.stock_quantity-OLD.stock_quantity,
            CASE WHEN has_context THEN COALESCE(NEW.stock_source_type,'manual') ELSE 'manual' END,
            CASE WHEN has_context THEN NEW.stock_source_id END, CASE WHEN has_context THEN NEW.stock_source_reference END,
            CASE WHEN has_context THEN NEW.stock_operation_uuid END, CASE WHEN has_context THEN NEW.stock_actor_id END,CURRENT_TIMESTAMP);
    END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION stock_ledger_immutable_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Stock movements are immutable; record a correcting movement'; END $$;
DROP TRIGGER IF EXISTS stock_ledger_insert ON branch_products;
CREATE TRIGGER stock_ledger_insert AFTER INSERT ON branch_products FOR EACH ROW EXECUTE FUNCTION stock_ledger_insert_fn();
DROP TRIGGER IF EXISTS stock_ledger_update ON branch_products;
CREATE TRIGGER stock_ledger_update AFTER UPDATE ON branch_products FOR EACH ROW EXECUTE FUNCTION stock_ledger_update_fn();
DROP TRIGGER IF EXISTS stock_ledger_no_update ON stock_movements;
CREATE TRIGGER stock_ledger_no_update BEFORE UPDATE ON stock_movements FOR EACH ROW EXECUTE FUNCTION stock_ledger_immutable_fn();
DROP TRIGGER IF EXISTS stock_ledger_no_delete ON stock_movements;
CREATE TRIGGER stock_ledger_no_delete BEFORE DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION stock_ledger_immutable_fn();
CREATE OR REPLACE FUNCTION approved_payroll_delete_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.paid_amount > 0 OR EXISTS (SELECT 1 FROM payroll_periods WHERE id = OLD.payroll_period_id AND status IN ('approved','paid')) THEN RAISE EXCEPTION 'Approved payroll cannot be deleted'; END IF;
    RETURN OLD;
END $$;
DROP TRIGGER IF EXISTS approved_payroll_delete ON employee_payrolls;
CREATE TRIGGER approved_payroll_delete BEFORE DELETE ON employee_payrolls FOR EACH ROW EXECUTE FUNCTION approved_payroll_delete_fn();

DROP VIEW pos_period_owners;
COMMIT;
SELECT 'WaslaPOS PostgreSQL upgrade 2026-10-28 completed successfully' AS result;
