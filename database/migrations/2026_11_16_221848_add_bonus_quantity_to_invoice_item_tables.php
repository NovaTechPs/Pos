<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Free (bonus) units given with a line: they move stock but are not charged. */
    public function up(): void
    {
        foreach (['order_items', 'purchase_items', 'purchase_return_items'] as $tableName) {
            if (Schema::hasColumn($tableName, 'bonus_quantity')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('bonus_quantity', 12, 2)->default(0)->after('quantity');
            });
        }
    }

    public function down(): void
    {
        foreach (['order_items', 'purchase_items', 'purchase_return_items'] as $tableName) {
            if (Schema::hasColumn($tableName, 'bonus_quantity')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('bonus_quantity');
                });
            }
        }
    }
};
