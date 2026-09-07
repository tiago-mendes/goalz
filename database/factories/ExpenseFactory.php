<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'expense_category_id' => fn (array $attributes): int => ExpenseCategory::factory()->create(['user_id' => $attributes['user_id']])->id,
            'fixed_expense_id' => null, 'occurrence_year' => null, 'occurrence_month' => null,
            'payment_account_id' => null, 'credit_card_id' => null,
            'name' => fake()->words(2, true), 'amount' => '100.00',
            'expense_date' => '2026-09-10', 'description' => null, 'deleted_by_user_at' => null,
        ];
    }

    public function deleted(): static
    {
        return $this->state(fn (array $attributes): array => ['deleted_by_user_at' => now()]);
    }
}
