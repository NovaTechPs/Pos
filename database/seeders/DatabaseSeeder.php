<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Production defaults to system permissions only.
        if (! app()->environment(['local', 'testing'])) {
            $this->call(PermissionSeeder::class);

            return;
        }

        $this->call(DemoDataSeeder::class);
    }
}
