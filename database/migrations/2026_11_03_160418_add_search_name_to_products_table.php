<?php

use App\Services\PosProductSearch;
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
        Schema::table('products', function (Blueprint $table): void {
            $table->string('search_name')->default('');
        });

        $search = app(PosProductSearch::class);
        DB::table('products')->select(['id', 'name'])->orderBy('id')->chunkById(500, function ($products) use ($search): void {
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update(['search_name' => $search->normalize($product->name)]);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(['tenant_id', 'search_name'], 'products_tenant_search_name_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_tenant_search_name_index');
            $table->dropColumn('search_name');
        });
    }
};
