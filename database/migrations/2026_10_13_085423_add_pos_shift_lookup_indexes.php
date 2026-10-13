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
        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['tenant_id', 'branch_id', 'opened_by', 'status', 'id'], 'shifts_active_lookup_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'branch_id', 'shift_id', 'status'], 'orders_shift_summary_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'branch_id', 'shift_id', 'payment_method'], 'payments_shift_summary_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_shift_summary_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_shift_summary_index');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('shifts_active_lookup_index');
        });
    }
};
