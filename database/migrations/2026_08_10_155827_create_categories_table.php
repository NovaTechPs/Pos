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
        Schema::create('categories', function (Blueprint $table) {
          $table->id();

            // ربط التصنيف بمتجر معين (Tenant)
            $table->foreignId('tenant_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('name'); // اسم التصنيف (مثل: مشروبات، حلويات...)
            $table->string('code')->nullable(); // كود اختياري للتصنيف
            $table->text('description')->nullable(); // وصف التصنيف

            // حالات وأولوية الترتيب
            $table->boolean('is_active')->default(true); // تفعيل/تعطيل التصنيف
            $table->integer('sort_order')->default(0); // ترتيب الظهور في الشاشة

            $table->timestamps();
            $table->softDeletes(); // للحذف المرن

            // فهرس مركزي لسرعة جلب تصنيفات متجر معين مرتبة
            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
