<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FixedExpense> */
class FixedExpenseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'expense_category_id' => fn (array $attributes): int => ExpenseCategory::factory()->create(['user_id' => $attributes['user_id']])->id,
            'payment_account_id' => null,
            'credit_card_id' => null,
            'name' => fake()->words(2, true),
            'amount' => (string) fake()->numberBetween(1, 10000).'.00',
            'day_of_month' => fake()->numberBetween(1, 31),
            'start_date' => '2026-09-01',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
