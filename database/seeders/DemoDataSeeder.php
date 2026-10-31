<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data is only available in local and testing environments.');
        }

        $password = config('client-setup.password');
        try {
            config(['client-setup.password' => $password ?: 'Local-Demo-Password42!']);
            $this->call(ClientSetupSeeder::class);
        } finally {
            config(['client-setup.password' => $password]);
        }
    }
}
