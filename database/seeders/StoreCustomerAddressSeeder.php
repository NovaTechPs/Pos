<?php

namespace Database\Seeders;

use App\Models\StoreCustomerAddress;
use Illuminate\Database\Seeder;

class StoreCustomerAddressSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            StoreCustomerAddress::factory()->create();
        }
    }
}
