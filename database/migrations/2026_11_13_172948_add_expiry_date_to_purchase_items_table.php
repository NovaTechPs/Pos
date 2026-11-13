<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Optional expiry date of each purchased batch, for stores that sell goods that expire. */
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->date('expiry_date')->nullable()->after('subtotal');
            $table->index(['expiry_date', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropIndex(['expiry_date', 'product_id']);
            $table->dropColumn('expiry_date');
        });
    }
};
