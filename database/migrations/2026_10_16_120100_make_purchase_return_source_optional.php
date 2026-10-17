<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->foreignId('purchase_id')->nullable()->change();
        });

        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->foreignId('purchase_item_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['tenant_id', 'customer_id', 'type', 'created_at'], 'orders_party_return_lookup');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(['product_id', 'order_id'], 'order_items_product_order_lookup');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->index(['tenant_id', 'supplier_id', 'created_at'], 'purchases_party_return_lookup');
        });

        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->index(['product_id', 'purchase_return_id'], 'purchase_return_items_product_return_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->dropIndex('purchase_return_items_product_return_lookup');
        });
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropIndex('purchases_party_return_lookup');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_product_order_lookup');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_party_return_lookup');
        });

        // Keep the nullable source fields to preserve party-based return records.
    }
};
