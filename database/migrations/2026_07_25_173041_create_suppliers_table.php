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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // الربط مع المستأجر (Tenant) لضمان فصل بيانات المشتركين
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // بيانات المورد الأساسية
            $table->string('name');                      // اسم المورد أو الشركة
            $table->string('company_name')->nullable();  // اسم الشركة / المؤسسة (إن وجد)
            $table->string('phone')->nullable();         // رقم الهاتف الرئيسي
            $table->string('email')->nullable();         // البريد الإلكتروني
            $table->string('tax_number')->nullable();    // الرقم الضريبي للمورد

            // العنوان والتفاصيل
            $table->string('address')->nullable();       // العنوان
            $table->decimal('opening_balance', 12, 2)->default(0.00); // الرصيد الافتتاحي (ديون سابقة)
            $table->text('notes')->nullable();           // ملاحظات إضافية

            $table->timestamps();

            // دعم الحذف المرن للحفاظ على تاريخ فواتير المشتريات القديمة
            $table->softDeletes();

            // فهارس لتحسين سرعة البحث
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
