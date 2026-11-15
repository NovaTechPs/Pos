<?php

namespace Database\Factories;

use App\Models\StoreCustomer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StoreCustomer> */
class StoreCustomerFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => fn (): int => Tenant::query()->create(['name' => fake()->company(), 'is_active' => true])->id, 'name' => fake()->name(), 'phone' => fake()->unique()->numerify('059#######'), 'password' => 'Customer-password-123'];
    }
}
