<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeTask;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTask>
 */
class EmployeeTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => fn (): int => Employee::create([
                'tenant_id' => Tenant::create(['name' => fake()->company(), 'is_active' => true])->id,
                'employee_code' => 'EMP-'.fake()->unique()->numerify('#####'),
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
                'hire_date' => today(),
            ])->id,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'priority' => 'normal',
            'status' => 'pending',
            'due_date' => today()->addDays(fake()->numberBetween(0, 7)),
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => ['employee_id' => $employee->id]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => 'completed']);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => ['due_date' => today()->subDays(2), 'status' => 'pending']);
    }
}
