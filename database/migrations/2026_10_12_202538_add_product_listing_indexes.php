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
        Schema::table('products', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'products_tenant_created_at_index');
            $table->index(['tenant_id', 'show_in_website'], 'products_tenant_website_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_tenant_created_at_index');
            $table->dropIndex('products_tenant_website_index');
        });
    }
};
