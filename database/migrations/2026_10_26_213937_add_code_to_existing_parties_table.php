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
        if (! Schema::hasColumn('parties', 'code')) {
            Schema::table('parties', function (Blueprint $table): void {
                $table->string('code')->nullable();
            });
        }

        if (! Schema::hasIndex('parties', ['tenant_id', 'code'], 'unique')) {
            Schema::table('parties', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'code']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
