<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('original_order_id')->nullable()->after('customer_id')->constrained('orders')->nullOnDelete();
            $table->index(['tenant_id', 'original_order_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('original_order_item_id')->nullable()->after('order_id')->constrained('order_items')->nullOnDelete();
            $table->index(['tenant_id', 'original_order_item_id']);
        });

        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_number');
            $table->decimal('total', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'reference_number']);
            $table->index(['tenant_id', 'purchase_id', 'created_at']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
            $table->index(['tenant_id', 'purchase_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('original_order_item_id');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('original_order_id');
        });
    }
};
