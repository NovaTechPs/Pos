<?php

use App\Services\RenumberLegacySalesInvoices;
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
            $table->string('previous_invoice_number')->nullable()->after('invoice_number');
            $table->index(['tenant_id', 'previous_invoice_number']);
        });

        app(RenumberLegacySalesInvoices::class)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->whereNotNull('previous_invoice_number')
            ->update(['invoice_number' => DB::raw('previous_invoice_number')]);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'previous_invoice_number']);
            $table->dropColumn('previous_invoice_number');
        });
    }
};
