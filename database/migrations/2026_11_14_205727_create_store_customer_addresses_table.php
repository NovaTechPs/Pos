<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_customer_id');
            $table->string('label', 50);
            $table->string('recipient_name');
            $table->string('phone', 30);
            $table->string('city', 100);
            $table->string('address_line', 350);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->foreign(['tenant_id', 'store_customer_id'], 'address_store_customer_fk')->references(['tenant_id', 'id'])->on('store_customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_customer_addresses');
    }
};
