<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expense vouchers (payments to no account) get a type, e.g. «دعاية وإعلان».
     * Cash expenses already saved from the POS wrote their type at the start of the notes.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('expense_category', 80)->nullable()->after('payment_method');
        });

        foreach (['كهرباء', 'مياه', 'إيجار', 'نثريات', 'مواصلات', 'أخرى'] as $category) {
            DB::table('payments')
                ->where('type', 'payment')
                ->whereNull('party_id')->whereNull('order_id')->whereNull('purchase_id')
                ->whereNull('purchase_return_id')->whereNull('employee_payment_id')
                ->whereNull('expense_category')
                ->where(fn ($notes) => $notes->where('notes', $category)->orWhere('notes', 'like', $category.' — %'))
                ->update(['expense_category' => $category]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('expense_category');
        });
    }
};
