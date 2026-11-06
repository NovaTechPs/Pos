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
        Schema::table('stock_voucher_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 12, 2)->default(0)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_voucher_items', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });
    }
};
