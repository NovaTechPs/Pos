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
        Schema::create('products', function (Blueprint $table) {
          $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('barcode')->nullable();
            $table->decimal('cost_price', 12, 2)->default(0.00);
            $table->decimal('retail_price', 12, 2);
            $table->decimal('wholesale_price', 12, 2);
            $table->integer('min_wholesale_quantity')->default(1);
            $table->timestamps();
$table->softDeletes();
            // الفهرس المركب لسرعة قراءة الباركود لكل تاجر
            $table->index(['tenant_id', 'barcode']);
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
