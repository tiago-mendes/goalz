<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalAccountAllocation>
 */
class GoalAccountAllocationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'account_id' => function (array $attributes): int {
                $goal = Goal::query()->findOrFail($attributes['goal_id']);

                return Account::factory()->for($goal->user)->create()->id;
            },
            'amount' => '50.00',
        ];
    }
}
