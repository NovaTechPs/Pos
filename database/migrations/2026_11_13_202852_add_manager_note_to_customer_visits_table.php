<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the manager asks the rep to do at this customer, apart from the rep's own visit note. */
    public function up(): void
    {
        Schema::table('customer_visits', function (Blueprint $table): void {
            $table->text('manager_note')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('customer_visits', function (Blueprint $table): void {
            $table->dropColumn('manager_note');
        });
    }
};
