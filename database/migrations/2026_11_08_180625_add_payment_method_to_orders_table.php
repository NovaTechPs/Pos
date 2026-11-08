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
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'cheque'])->nullable()->after('paid_amount');
        });

        DB::table('orders')->where('type', 'pos')->whereNull('payment_method')->update([
            'payment_method' => DB::raw('(SELECT payments.payment_method FROM payments WHERE payments.order_id = orders.id AND payments.deleted_at IS NULL ORDER BY payments.id DESC LIMIT 1)'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
