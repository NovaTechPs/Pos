<?php

namespace Database\Factories;

use App\Models\PayrollHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollHoliday>
 */
class PayrollHolidayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'name' => 'عطلة رسمية',
            'is_paid' => true,
        ];
    }
}
