<?php

namespace Database\Factories;

use App\GoalMilestone;
use App\Models\Goal;
use App\Models\GoalMilestoneAchievement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalMilestoneAchievement>
 */
class GoalMilestoneAchievementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'milestone_percentage' => GoalMilestone::TwentyFive,
            'achieved_at' => now(),
        ];
    }
}
