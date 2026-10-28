<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SchemaIntegrity::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('original_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('invoice_number');
            $table->enum('type', ['pos', 'wholesale', 'online', 'return'])->default('pos');
            $table->enum('source', ['pos', 'web', 'api', 'online', 'sync'])->default('web');
            $table->enum('sale_kind', ['retail', 'wholesale'])->default('retail');
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled'])->default('completed');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->enum('discount_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('discount_rate', 8, 2)->default(0.00);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('delivery_fee', 12, 2)->default(0.00);

            $table->decimal('total', 12, 2);
            $table->decimal('total_cost', 12, 2)->default(0.00);
            $table->decimal('total_profit', 12, 2)->default(0.00);
            $table->decimal('paid_amount', 12, 2)->default(0.00);
            $table->enum('payment_status', ['paid', 'partial', 'unpaid', 'refunded'])->default('paid');
            $table->text('notes')->nullable();
            $table->uuid('sync_uuid')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'invoice_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'type']);
            $table->index(['tenant_id', 'source', 'sale_kind', 'created_at'], 'orders_source_kind_date_index');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'branch_id', 'created_at']);
            $table->index(['tenant_id', 'customer_id', 'type', 'created_at'], 'orders_party_return_lookup');
            $table->index(['tenant_id', 'original_order_id']);
            $table->index(['tenant_id', 'branch_id', 'shift_id', 'status'], 'orders_shift_summary_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
