<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'shifts'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'sync_uuid')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->uuid('sync_uuid')->nullable()->unique();
                });
            }
            DB::table($tableName)->whereNull('sync_uuid')->orderBy('id')->chunkById(500, function ($rows) use ($tableName): void {
                foreach ($rows as $row) {
                    DB::table($tableName)->where('id', $row->id)->update(['sync_uuid' => (string) Str::uuid()]);
                }
            });
        }

        foreach (['tenant_id' => 'tenants', 'branch_id' => 'branches'] as $column => $target) {
            if (! Schema::hasColumn('employees', $column)) {
                Schema::table('employees', function (Blueprint $table) use ($column, $target): void {
                    $table->foreignId($column)->nullable()->constrained($target)->nullOnDelete();
                });
            }
            DB::table('employees')->whereNull($column)->whereNotNull('user_id')->orderBy('id')->chunkById(500, function ($rows) use ($column): void {
                foreach ($rows as $row) {
                    $value = DB::table('users')->where('id', $row->user_id)->value($column);
                    if ($value !== null) {
                        DB::table('employees')->where('id', $row->id)->update([$column => $value]);
                    }
                }
            });
        }

        if (! Schema::hasIndex('employees', ['user_id'], 'unique')) {
            if (DB::table('employees')->select('user_id')->whereNotNull('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Resolve duplicate employee user accounts before applying the release migration.');
            }
            Schema::table('employees', fn (Blueprint $table) => $table->unique('user_id'));
        }

        foreach (['orders' => ['original_order_id', 'orders'], 'order_items' => ['original_order_item_id', 'order_items']] as $tableName => [$column, $target]) {
            if (! Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, function (Blueprint $table) use ($column, $target): void {
                    $table->foreignId($column)->nullable()->constrained($target)->nullOnDelete();
                });
            }
        }

        foreach (['purchase_returns' => 'purchase_id', 'purchase_return_items' => 'purchase_item_id'] as $tableName => $column) {
            $definition = collect(Schema::getColumns($tableName))->firstWhere('name', $column);
            if ($definition && ! $definition['nullable']) {
                Schema::table($tableName, fn (Blueprint $table) => $table->foreignId($column)->nullable()->change());
            }
        }

        $indexes = [
            'products' => [
                'products_tenant_name_index' => ['tenant_id', 'name'],
                'products_tenant_created_at_index' => ['tenant_id', 'created_at'],
                'products_tenant_website_index' => ['tenant_id', 'show_in_website'],
            ],
            'shifts' => ['shifts_active_lookup_index' => ['tenant_id', 'branch_id', 'opened_by', 'status', 'id']],
            'orders' => [
                'orders_shift_summary_index' => ['tenant_id', 'branch_id', 'shift_id', 'status'],
                'orders_party_return_lookup' => ['tenant_id', 'customer_id', 'type', 'created_at'],
                'orders_tenant_id_original_order_id_index' => ['tenant_id', 'original_order_id'],
            ],
            'order_items' => [
                'order_items_product_order_lookup' => ['product_id', 'order_id'],
                'order_items_tenant_id_original_order_item_id_index' => ['tenant_id', 'original_order_item_id'],
            ],
            'payments' => ['payments_shift_summary_index' => ['tenant_id', 'branch_id', 'shift_id', 'payment_method']],
            'purchases' => ['purchases_party_return_lookup' => ['tenant_id', 'supplier_id', 'created_at']],
            'purchase_return_items' => ['purchase_return_items_product_return_lookup' => ['product_id', 'purchase_return_id']],
            'employee_bonuses' => ['employee_bonuses_employee_date_index' => ['employee_id', 'date']],
            'employee_deductions' => ['employee_deductions_employee_date_index' => ['employee_id', 'date']],
            'employee_leaves' => ['employee_leaves_employee_status_start_index' => ['employee_id', 'status', 'start_date']],
            'employee_advances' => ['employee_advances_employee_status_start_index' => ['employee_id', 'status', 'start_installment_date']],
            'employee_payments' => ['employee_payments_employee_date_index' => ['employee_id', 'payment_date']],
        ];
        foreach ($indexes as $tableName => $tableIndexes) {
            foreach ($tableIndexes as $name => $columns) {
                if (! Schema::hasIndex($tableName, $columns)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
                }
            }
        }
    }

    /** Preserve existing data and schema when rolling back this additive compatibility migration. */
    public function down(): void {}
};
