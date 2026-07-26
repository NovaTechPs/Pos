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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete(); // المورد
            $table->foreignId('user_id')->constrained(); // الموظف الذي أدخل الفاتورة
            $table->string('reference_number')->nullable(); // رقم فاتورة المورد
            $table->decimal('total', 12, 2);
            $table->decimal('paid_amount', 12, 2);
            $table->enum('payment_status', ['paid', 'partial', 'unpaid'])->default('paid');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
