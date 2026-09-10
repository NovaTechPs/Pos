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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // الفرع اختياري في طلبات أونلاين (أو يحدد فرع رئيسي لاحقاً)
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->nullOnDelete();

            // الكاشير/الموظف اختياري لأن أوردر المتجر ينشئه الزبون بنفسه
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // اختياري في POS وأونلاين، إجباري في الجملة (إن وجد حساب زبون مسجل)
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // بيانات الزبون الشاحن المباشرة (خاصة بطلبات أونلاين / Zibn Direct)
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();

            $table->string('invoice_number');

            // تمييز نوع الفاتورة: كاشير (pos)، جملة (wholesale)، أو متجر إلكتروني (online)
            $table->enum('type', ['pos', 'wholesale', 'online'])->default('pos');

            // حالة الطلب للطلبات الإلكترونية (معلقة، قيد التجهيز، مكتملة...)
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled'])->default('completed');

            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('total', 12, 2);

            // حقول التكلفة والربح
            $table->decimal('total_cost', 12, 2)->default(0.00);
            $table->decimal('total_profit', 12, 2)->default(0.00);

            $table->decimal('paid_amount', 12, 2)->default(0.00);
            $table->enum('payment_status', ['paid', 'partial', 'unpaid'])->default('paid');
            $table->timestamps();
            $table->softDeletes();

            // الفهارس تحسّن سرعة الاستعلامات حسب النوع والتاجر
            $table->index(['tenant_id', 'branch_id', 'type']);
            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
