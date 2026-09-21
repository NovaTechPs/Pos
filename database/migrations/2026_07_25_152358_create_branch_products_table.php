<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_products', function (Blueprint $table) {
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
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['branch_id', 'stock_quantity', 'alert_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_products');
    }
};
