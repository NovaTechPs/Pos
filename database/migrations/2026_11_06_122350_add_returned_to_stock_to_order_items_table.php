<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('returned_to_stock')->default(true);
        });

        DB::table('order_items')->whereIn('order_id', DB::table('orders')
            ->select('id')->where('type', 'return')->where('invoice_number', 'like', 'RET-CASH-%')
            ->where('notes', 'like', 'مرتجع نقدي مكسور/تالف - لم يُعد للمخزون.%'))
            ->update(['returned_to_stock' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('returned_to_stock');
        });
    }
};
