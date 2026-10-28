<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SchemaIntegrity::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('original_order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->decimal('cost_price', 12, 2)->default(0.00);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('total_cost', 12, 2)->default(0.00);
            $table->foreignId('offer_id')->nullable()->constrained('product_offers')->nullOnDelete();
            $table->boolean('is_gift')->default(false);
            $table->decimal('offer_quantity', 12, 3)->nullable();
            $table->decimal('offer_price', 12, 2)->nullable();
            $table->decimal('normal_subtotal', 12, 2)->default(0);
            $table->decimal('promotion_savings', 12, 2)->default(0);

            $table->timestamps();

            $table->foreign(['tenant_id', 'order_id'], 'order_items_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'order_items_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'original_order_item_id']);
            $table->index(['product_id', 'order_id'], 'order_items_product_order_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
