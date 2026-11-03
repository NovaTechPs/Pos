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
        Schema::table('purchases', function (Blueprint $table): void {
            $table->decimal('tax_amount', 12, 2)->default(0)->after('total');
            $table->decimal('tax_percent', 8, 2)->default(0)->after('tax_amount');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('tax_percent', 8, 2)->default(0)->after('tax_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('tax_percent');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'tax_percent']);
        });
    }
};
