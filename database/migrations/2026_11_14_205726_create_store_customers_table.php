<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 30);
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('email')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_customers');
    }
};
