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
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            // مفتاح الميزة مثل: 'max_products', 'pos_access', 'multi_branch'
            $table->string('feature_key');

            // قيمة الميزة: قد تكون 'true' للميزات المفتوحة، أو رقماً مثل '100' للحدود
            $table->string('value')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
