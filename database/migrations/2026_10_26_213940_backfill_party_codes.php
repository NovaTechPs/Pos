<?php

use App\Services\PartyCodeGenerator;
use App\Services\SchemaIntegrity;
use Brick\Math\BigInteger;
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
                $nextCode = BigInteger::of(app(PartyCodeGenerator::class)->nextCode(
                    DB::table('parties')->where('tenant_id', $tenant->id),
                ));

                DB::table('parties')->where('tenant_id', $tenant->id)
                    ->where(fn ($query) => $query->whereNull('code')->orWhere('code', ''))
                    ->orderBy('id')->eachById(function (object $party) use (&$nextCode): void {
                        DB::table('parties')->where('id', $party->id)->update(['code' => (string) $nextCode]);
                        $nextCode = $nextCode->plus(1);
                    });
            });
        });
        SchemaIntegrity::finalize();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Assigned codes are permanent identifiers and must survive rollback.
    }
};
