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
        Schema::create('shifts', function (Blueprint $table) {
     $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_settlement_id')->nullable()->constrained('daily_settlements')->nullOnDelete();

            // المبالغ النقدية ومطابقة الصندوق
            $table->decimal('opening_cash', 10, 2)->default(0);    // الرصيد الافتتاحي (الفكة)
            $table->decimal('expected_cash', 10, 2)->nullable();   // النقد المتوقع وجوده
            $table->decimal('actual_cash', 10, 2)->nullable();     // النقد الفعلي بعد العد
            $table->decimal('difference', 10, 2)->default(0);      // الفارق (عجز / زيادة)

            // إحصائيات المبيعات والمرتجعات للشيفت
            $table->decimal('total_sales', 10, 2)->default(0);
            $table->decimal('total_returns', 10, 2)->default(0);

            // حالة الشيفت والتوقيت
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
