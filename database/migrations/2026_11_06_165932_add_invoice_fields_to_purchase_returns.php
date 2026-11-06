<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addSubtotal = ! Schema::hasColumn('purchase_returns', 'subtotal');
        foreach (['subtotal', 'discount_rate', 'discount', 'tax_amount', 'paid_amount'] as $column) {
            if (! Schema::hasColumn('purchase_returns', $column)) {
                Schema::table('purchase_returns', fn (Blueprint $table) => $table->decimal($column, 12, 2)->default(0));
            }
        }
        foreach (['discount_type' => 'fixed', 'payment_method' => 'cash'] as $column => $default) {
            if (! Schema::hasColumn('purchase_returns', $column)) {
                Schema::table('purchase_returns', fn (Blueprint $table) => $table->string($column)->default($default));
            }
        }
        if ($addSubtotal) {
            DB::table('purchase_returns')->update(['subtotal' => DB::raw('total')]);
        }
        $unsigned = str_contains(Schema::getColumnType('purchase_returns', 'id', true), 'unsigned');
        if (! Schema::hasColumn('payments', 'purchase_return_id')) {
            Schema::table('payments', fn (Blueprint $table) => $table->bigInteger('purchase_return_id')->unsigned($unsigned)->nullable());
        } elseif (DB::getDriverName() === 'mysql' && str_contains(Schema::getColumnType('payments', 'purchase_return_id', true), 'unsigned') !== $unsigned) {
            Schema::table('payments', fn (Blueprint $table) => $table->bigInteger('purchase_return_id')->unsigned($unsigned)->nullable()->change());
        }
        $hasForeign = collect(Schema::getForeignKeys('payments'))->contains(fn (array $key): bool => $key['columns'] === ['purchase_return_id']);
        if (! $hasForeign) {
            Schema::table('payments', fn (Blueprint $table) => $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_return_id');
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropColumn(['subtotal', 'discount_type', 'discount_rate', 'discount', 'tax_amount', 'paid_amount', 'payment_method']);
        });
    }
};
