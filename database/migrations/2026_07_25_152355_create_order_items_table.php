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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();

            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 2); // سعر البيع للقطعة
            $table->decimal('total_price', 12, 2); // إجمالي البيع = الكمية * سعر البيع

            // 💡 الحقول المضافة لحساب الربح والتكلفة:
            $table->decimal('cost_price', 12, 2)->default(0); // سعر شراء/تكلفة القطعة الواحدة وقت البيع
            $table->decimal('total_cost', 12, 2)->default(0); // إجمالي تكلفة السطر = الكمية * سعر التكلفة

            $table->timestamps(); // يُفضل دائماً وجود التواريخ
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
