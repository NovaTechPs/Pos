<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WebCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebCategory> */
class WebCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => fn (): int => Tenant::query()->create(['name' => fake()->company()])->id, 'name' => fake()->unique()->words(3, true)];
    }
}
