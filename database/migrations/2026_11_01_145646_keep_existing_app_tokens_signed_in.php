<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('name', 'mobile-app')
            ->whereNotNull('expires_at')
            ->update(['expires_at' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Previous expiry times cannot be reconstructed without ending active app sessions.
    }
};
