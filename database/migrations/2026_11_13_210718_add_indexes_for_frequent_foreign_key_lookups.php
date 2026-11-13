<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns the screens filter or join on most. PostgreSQL does not index foreign keys on its own,
     * and leading with tenant_id keeps these from duplicating the indexes MySQL adds for them.
     *
     * @var array<string, list<list<string>>>
     */
    private const INDEXES = [
        'payments' => [['tenant_id', 'order_id'], ['tenant_id', 'purchase_id'], ['tenant_id', 'purchase_return_id'], ['tenant_id', 'created_by']],
        'orders' => [['tenant_id', 'created_by', 'created_at']],
        'purchase_returns' => [['tenant_id', 'supplier_id']],
        'parties' => [['tenant_id', 'branch_id']],
        'users' => [['tenant_id', 'role_id']],
        'employees' => [['tenant_id', 'branch_id']],
        'stock_voucher_items' => [['tenant_id', 'product_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $columns) {
                    $blueprint->index($columns);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $columns) {
                    $blueprint->dropIndex($columns);
                }
            });
        }
    }
};
