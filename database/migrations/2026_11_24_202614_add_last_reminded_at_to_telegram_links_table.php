<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a linked customer was last reminded of their unpaid balance, so a reminder repeats only
     * after the store's chosen number of days.
     */
    public function up(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('linked_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropColumn('last_reminded_at');
        });
    }
};
