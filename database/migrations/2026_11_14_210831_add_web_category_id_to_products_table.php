<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE products ADD COLUMN web_category_id INTEGER REFERENCES web_categories(id) ON DELETE RESTRICT');
            foreach (['insert', 'update'] as $event) {
                DB::statement("CREATE TRIGGER products_web_category_{$event} BEFORE {$event} ON products FOR EACH ROW WHEN NEW.web_category_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM web_categories WHERE id = NEW.web_category_id AND tenant_id = NEW.tenant_id) BEGIN SELECT RAISE(ABORT, 'Cross-tenant web category'); END");
            }

            return;
        }
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('web_category_id')->nullable();
            $table->foreign(['tenant_id', 'web_category_id'], 'products_web_category_tenant_fk')->references(['tenant_id', 'id'])->on('web_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS products_web_category_insert');
            DB::statement('DROP TRIGGER IF EXISTS products_web_category_update');
            DB::statement('ALTER TABLE products DROP COLUMN web_category_id');

            return;
        }
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign('products_web_category_tenant_fk');
            $table->dropColumn('web_category_id');
        });
    }
};
