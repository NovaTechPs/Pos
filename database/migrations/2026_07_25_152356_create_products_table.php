<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->nullOnDelete();
            $table->string('name');
            $table->decimal('cost_price', 12, 2)->default(0.00);
            $table->boolean('is_price_unified')->default(true);
            $table->boolean('show_in_website')->default(false);
            $table->decimal('website_price', 10, 2)->nullable();
            $table->string('image')->nullable();
            $table->json('images')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
