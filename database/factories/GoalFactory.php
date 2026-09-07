<?php

namespace Database\Factories;

use App\GoalStatus;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Goal> */
class GoalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'target_amount' => '1000.00',
            'target_date' => null,
            'status' => GoalStatus::Active,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => GoalStatus::Paused]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => GoalStatus::Completed]);
    }
}
