<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_customer_id')->nullable();
            $table->foreign(['tenant_id', 'store_customer_id'], 'order_store_customer_fk')->references(['tenant_id', 'id'])->on('store_customers')->restrictOnDelete();
            $table->index(['tenant_id', 'store_customer_id', 'created_at'], 'order_store_customer_history');
        });
        Schema::table('customer_order_items', function (Blueprint $table): void {
            $table->string('product_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_order_items', function (Blueprint $table): void {
            $table->dropColumn('product_name');
        });
        Schema::table('customer_orders', function (Blueprint $table): void {
            $table->dropForeign('order_store_customer_fk');
            $table->dropIndex('order_store_customer_history');
            $table->dropColumn('store_customer_id');
        });
    }
};
