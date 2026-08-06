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
            Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Basic, Pro, Enterprise
            $table->string('slug')->unique(); // basic, pro, enterprise

            $table->text('description')->nullable(); // وصف الخطة

            $table->decimal('price', 8, 2)->default(0.00); // السعر

            $table->integer('invoice_period')->default(1); // e.g. 1, 3, 12
            $table->string('invoice_interval')->default('month'); // e.g. day, month, year

            $table->boolean('is_active')->default(true); // ميزة إضافية: لتفعيل/تجميد الخطة
            $table->timestamps();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
