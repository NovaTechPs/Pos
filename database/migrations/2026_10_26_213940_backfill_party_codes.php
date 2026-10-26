<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('tenants')->orderBy('id')->eachById(function (object $tenant): void {
            DB::transaction(function () use ($tenant): void {
                DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
                $nextCode = (int) DB::table('parties')->where('tenant_id', $tenant->id)
                    ->max(DB::raw('CAST(code AS DECIMAL(20, 0))'));

                DB::table('parties')->where('tenant_id', $tenant->id)
                    ->where(fn ($query) => $query->whereNull('code')->orWhere('code', ''))
                    ->orderBy('id')->eachById(function (object $party) use (&$nextCode): void {
                        DB::table('parties')->where('id', $party->id)->update(['code' => (string) ++$nextCode]);
                    });
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Assigned codes are permanent identifiers and must survive rollback.
    }
};
