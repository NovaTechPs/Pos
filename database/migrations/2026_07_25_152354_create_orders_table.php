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
    $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained(); // الكاشير أو الموظف
    $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); // اختياري في POS، إجباري في الجملة

    $table->string('invoice_number');

    // تمييز نوع الفاتورة
    $table->enum('type', ['pos', 'wholesale'])->default('pos');

    $table->decimal('subtotal', 12, 2);
    $table->decimal('discount', 12, 2)->default(0.00);
    $table->decimal('total', 12, 2);
    $table->decimal('paid_amount', 12, 2);
    $table->enum('payment_status', ['paid', 'partial', 'unpaid'])->default('paid');

    $table->timestamps();
    $table->softDeletes();

    $table->index(['tenant_id', 'branch_id', 'type']);
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
