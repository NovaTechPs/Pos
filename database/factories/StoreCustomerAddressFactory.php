<?php

namespace Database\Factories;

use App\Models\StoreCustomer;
use App\Models\StoreCustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreCustomerAddress> */
class StoreCustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        return ['store_customer_id' => StoreCustomer::factory(), 'tenant_id' => fn (array $attributes): int => (int) StoreCustomer::query()->findOrFail($attributes['store_customer_id'])->tenant_id,
            'label' => 'المنزل', 'recipient_name' => fake()->name(), 'phone' => '0599123456', 'city' => 'الخليل', 'address_line' => fake()->streetAddress()];
    }
}
