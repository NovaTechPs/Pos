<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The online shop: customers' sign-ins (each tied to a customer party), and their order requests,
     * which stay apart from sales until a staff member turns one into a real invoice.
     */
    public function up(): void
    {
        SchemaIntegrity::create('customer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->string('phone', 30);
            $table->string('password');
            $table->string('price_list', 20)->default('retail');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'party_id']);
        });

        SchemaIntegrity::create('customer_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->string('reference', 40);
            $table->string('tracking_token', 64)->unique();
            $table->string('customer_name');
            $table->string('customer_phone', 30);
            $table->string('customer_address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('price_list', 20)->default('retail');
            $table->string('status', 20)->default('new');
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'party_id']);
        });

        SchemaIntegrity::create('customer_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total', 15, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'customer_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_order_items');
        Schema::dropIfExists('customer_orders');
        Schema::dropIfExists('customer_accounts');
    }
};
