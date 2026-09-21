<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
{
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

        $table->enum('type', ['receipt', 'payment']); // سند قبض أو صرف
        $table->string('voucher_number');

        // Polymorphic relation للعميل/المورد أو أي جهة أخرى
        $table->nullableMorphs('payable');

        $table->decimal('amount', 12, 2);
        $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'cheque'])->default('cash');

        // ربط اختياري بفاتورة مبيعات أو مشتريات
        $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();

        $table->text('notes')->nullable();
        $table->timestamp('payment_date');
        $table->timestamps();
        $table->softDeletes();

        // Indexes
        $table->unique(['tenant_id', 'voucher_number']); // ضمان عدم تكرار رقم السند للعميل الواحد
        $table->index(['tenant_id', 'type', 'payment_date']);
        $table->index(['tenant_id', 'branch_id', 'payment_method']);
    });
}
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
