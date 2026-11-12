<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salary and advance payments are also recorded as payment vouchers, so the cash they take
     * appears in the drawer, cash flow and daily cash. The voucher keeps a link to its payment.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('employee_payment_id')->nullable()->unique()->constrained('employee_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_payment_id');
        });
    }
};
