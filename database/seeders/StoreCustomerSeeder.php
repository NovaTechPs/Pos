<?php

namespace Database\Seeders;

use App\Models\StoreCustomer;
use Illuminate\Database\Seeder;

class StoreCustomerSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            StoreCustomer::factory()->create();
        }
    }
}
