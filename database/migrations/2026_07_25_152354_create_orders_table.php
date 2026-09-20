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

    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

        // الفرع اختياري في طلبات أونلاين
        $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

        $table->foreignId('shift_id')->nullable();

        // الكاشير/الموظف اختياري
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

        // ** التعديل هنا: توجيه المفتاح الأجنبي نحو جدول parties **
        $table->foreignId('customer_id')->nullable()->constrained('parties')->nullOnDelete();

        // بيانات الزبون الشاحن المباشرة
        $table->string('customer_name')->nullable();
        $table->string('customer_phone')->nullable();
        $table->text('customer_address')->nullable();

        $table->string('invoice_number');

        // تمييز نوع الفاتورة
        $table->enum('type', ['pos', 'wholesale', 'online'])->default('pos');

        // حالة الطلب للطلبات الإلكترونية
        $table->enum('status', ['pending', 'processing', 'completed', 'cancelled'])->default('completed');

        $table->decimal('subtotal', 12, 2);
        $table->decimal('tax_amount', 12, 2)->default(0);
        $table->decimal('discount', 12, 2)->default(0.00);
        $table->decimal('total', 12, 2);

        // حقول التكلفة والربح
        $table->decimal('total_cost', 12, 2)->default(0.00);
        $table->decimal('total_profit', 12, 2)->default(0.00);

        $table->decimal('paid_amount', 12, 2)->default(0.00);
        $table->enum('payment_status', ['paid', 'partial', 'unpaid'])->default('paid');
        $table->text('notes')->nullable();
        $table->timestamps();
        $table->softDeletes();

        // الفهارس
        $table->index(['tenant_id', 'branch_id', 'type']);
        $table->index(['tenant_id', 'type', 'created_at']);
    });

    Schema::enableForeignKeyConstraints();
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
