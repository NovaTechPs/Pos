<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SchemaIntegrity::create('stock_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('voucher_number');
            $table->enum('type', ['in', 'out']);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'voucher_number']);
            $table->index(['tenant_id', 'branch_id', 'type', 'created_at']);
        });

        SchemaIntegrity::create('stock_voucher_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_voucher_id')->constrained('stock_vouchers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->timestamps();

            $table->index(['tenant_id', 'stock_voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_voucher_items');
        Schema::dropIfExists('stock_vouchers');
    }
};
