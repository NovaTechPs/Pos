<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('sync_uuid')->nullable()->unique();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->uuid('sync_uuid')->nullable()->unique();
        });

        DB::table('orders')->whereNull('sync_uuid')->orderBy('id')->chunkById(500, function ($orders): void {
            foreach ($orders as $order) {
                DB::table('orders')->where('id', $order->id)->update(['sync_uuid' => (string) Str::uuid()]);
            }
        });

        DB::table('shifts')->whereNull('sync_uuid')->orderBy('id')->chunkById(500, function ($shifts): void {
            foreach ($shifts as $shift) {
                DB::table('shifts')->where('id', $shift->id)->update(['sync_uuid' => (string) Str::uuid()]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['sync_uuid']);
            $table->dropColumn('sync_uuid');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['sync_uuid']);
            $table->dropColumn('sync_uuid');
        });
    }
};
