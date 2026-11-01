<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->index(['tenant_id', 'product_id', 'branch_id'], 'branch_products_tenant_product_branch_index');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX product_barcodes_lower_lookup_index ON product_barcodes (tenant_id, LOWER(barcode) text_pattern_ops, product_id)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->dropIndex('branch_products_tenant_product_branch_index');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS product_barcodes_lower_lookup_index');
        }
    }
};
