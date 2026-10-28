<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SchemaIntegrity::create('branch_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('stock_quantity', 12, 2)->default(0.00);
            $table->decimal('alert_quantity', 12, 2)->default(5.00);
            $table->decimal('retail_price', 12, 2)->default(0.00);
            $table->decimal('wholesale_price', 12, 2)->default(0.00);
            $table->decimal('offer_quantity', 12, 2)->nullable();
            $table->decimal('offer_price', 12, 2)->nullable();
            $table->decimal('min_wholesale_quantity', 12, 2)->default(1.00);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stock_source_type')->nullable();
            $table->unsignedBigInteger('stock_source_id')->nullable();
            $table->string('stock_source_reference')->nullable();
            $table->uuid('stock_operation_uuid')->nullable();
            $table->unsignedBigInteger('stock_actor_id')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'branch_id'], 'branch_products_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'branch_products_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['branch_id', 'stock_quantity', 'alert_quantity']);
        });

        SchemaIntegrity::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_before', 15, 3);
            $table->decimal('quantity_after', 15, 3);
            $table->decimal('quantity', 15, 3);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference')->nullable();
            $table->uuid('operation_uuid')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'branch_id', 'product_id', 'created_at'], 'stock_movement_lookup');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'stock_movement_document_lookup');
            $table->unique(['branch_product_id', 'operation_uuid'], 'stock_movement_operation_unique');
        });
        SchemaIntegrity::stockLedger();
    }

    public function down(): void
    {
        SchemaIntegrity::dropStockLedger();
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('branch_products');
    }
};
