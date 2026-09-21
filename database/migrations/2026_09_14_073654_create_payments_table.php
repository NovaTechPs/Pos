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
    Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // تحديد نوع السند: receipt = سند قبض (تحصيل), payment = سند دفع (مصروف/مورد)
            $table->enum('type', ['receipt', 'payment']);

            // رقم السند المرجعي
            $table->string('voucher_number')->index();

            // الجهة (زبون أو مورد)
            $table->nullableMorphs('payable'); // ينتج payable_type و payable_id (Customer أو Supplier)

            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'cheque'])->default('cash');

            // ربط اختياري بفاتورة مبيعات أو مشتريات
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamp('payment_date');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
