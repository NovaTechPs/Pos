<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closed_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('settlement_date');
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_returns', 12, 2)->default(0);
            $table->decimal('total_cash', 12, 2)->default(0);
            $table->decimal('total_card', 12, 2)->default(0);
            $table->integer('total_shifts_count')->default(0);
            $table->timestamp('closed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'settlement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_settlements');
    }
};
