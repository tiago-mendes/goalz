<?php

namespace Database\Factories;

use App\Models\MonthlyIncome;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonthlyIncome> */
class MonthlyIncomeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'year' => 2026,
            'month' => fake()->numberBetween(1, 12),
            'amount' => (string) fake()->numberBetween(0, 10000).'.00',
        ];
    }
}
