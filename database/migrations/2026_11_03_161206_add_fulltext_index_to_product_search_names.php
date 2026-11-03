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
        if (DB::getDriverName() === 'mysql') {
            Schema::table('products', function (Blueprint $table): void {
                $table->fullText('search_name', 'products_search_name_fulltext_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropFullText('products_search_name_fulltext_index');
            });
        }
    }
};
