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
        Schema::create('daily_settlements', function (Blueprint $table) {
          $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closed_by')->constrained('users')->cascadeOnDelete();

            // بيانات اليوم المالي والإحصائيات التجميعية
            $table->date('settlement_date');
            $table->decimal('total_sales', 10, 2)->default(0);
            $table->decimal('total_returns', 10, 2)->default(0);
            $table->decimal('total_cash', 10, 2)->default(0);
            $table->decimal('total_card', 10, 2)->default(0);
            $table->integer('total_shifts_count')->default(0);

            // وقت الإغلاق والملاحظات
            $table->timestamp('closed_at');
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_settlements');
    }
};
