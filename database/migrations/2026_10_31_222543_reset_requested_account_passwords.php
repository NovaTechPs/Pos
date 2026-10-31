<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        // One-time reset explicitly requested for every existing account.
        DB::table('users')->update([
            'password' => Hash::make('password'),
            'remember_token' => null,
            'updated_at' => now(),
        ]);
    }

    // The previous password hashes cannot be reconstructed on rollback.
    public function down(): void {}
};
