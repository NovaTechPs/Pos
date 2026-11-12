<?php

namespace Database\Factories;

use App\Models\CasualJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CasualJob>
 */
class CasualJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'worker_name' => fake()->name(),
            'title' => 'تحميل كونتينر',
            'job_date' => now()->toDateString(),
            'amount' => 150,
            'payment_method' => 'cash',
        ];
    }
}
