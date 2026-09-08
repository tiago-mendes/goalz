<?php

namespace Database\Factories;

use App\BudgetMode;
use App\Models\BudgetRule;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BudgetRule> */
class BudgetRuleFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'expense_category_id' => fn (array $attributes): int => ExpenseCategory::factory()->create(['user_id' => $attributes['user_id']])->id, 'amount' => '500.00', 'mode' => BudgetMode::Recurring, 'starts_month' => '2026-09-01', 'ends_month' => null];
    }
}
