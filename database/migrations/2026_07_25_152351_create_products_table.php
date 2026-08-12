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
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // ربط القسم / التصنيف (Category)
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete(); // في حال حذف التصنيف، يتم تعيين القيمة إلى NULL بدلاً من حذف المنتج

            $table->string('name');
            $table->string('barcode')->nullable();
            $table->decimal('cost_price', 12, 2)->default(0.00);
            $table->decimal('retail_price', 12, 2);
            $table->decimal('wholesale_price', 12, 2);
            $table->integer('min_wholesale_quantity')->default(1);
            $table->integer('offer_quantity')->nullable(); // عدد الحبات المطلوب للعرض
            $table->decimal('offer_price', 10, 2)->nullable(); // سعر المجموعة كاملة
            $table->timestamps();
            $table->softDeletes();

            // الفهارس
            $table->index(['tenant_id', 'barcode']);
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
