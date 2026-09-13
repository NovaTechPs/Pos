<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
                Schema::disableForeignKeyConstraints();

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->cascadeOnDelete();

            // ربط القسم / التصنيف (Category)
            $table->foreignId('category_id')
                ->nullable()
                ->nullOnDelete();
            $table->string('name');
            $table->decimal('cost_price', 12, 2)->default(0.00);
            $table->boolean('is_price_unified')->default(true)->after('min_wholesale_quantity');
            $table->boolean('show_in_website')->default(false); // أو false حسب رغبتك بالافتراضي
          $table->string('image')->nullable();
          $table->json('images')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // الفهارس
            $table->index(['tenant_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
