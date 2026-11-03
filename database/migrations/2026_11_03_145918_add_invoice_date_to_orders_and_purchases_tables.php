<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->date('invoice_date')->nullable()->after('invoice_number');
            $table->unsignedBigInteger('delivery_party_id')->nullable()->after('delivery_fee');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->date('invoice_date')->nullable()->after('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn('invoice_date');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['invoice_date', 'delivery_party_id']);
        });
    }
};
