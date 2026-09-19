<?php

namespace Database\Factories;

use App\GoalMilestone;
use App\Models\Goal;
use App\Models\GoalReward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalReward>
 */
class GoalRewardFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'milestone_percentage' => GoalMilestone::TwentyFive,
            'title' => fake()->words(3, true),
            'description' => null,
        ];
    }
}
