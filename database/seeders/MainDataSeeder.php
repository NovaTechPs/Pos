<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MainDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(736191031)');
            }
            // Startup must never replace existing accounts, even soft-deleted ones.
            if (User::withTrashed()->exists()) {
                return;
            }
            $password = config('client-setup.password');
            if (! is_string($password) || $password === '') {
                $this->command?->warn('Main setup skipped: set CLIENT_SEED_PASSWORD in the hosting environment and redeploy.');

                return;
            }
            // No catalog, invoices, balances or destructive commands are seeded here.
            $this->call(ClientSetupSeeder::class);
        });
    }
}
