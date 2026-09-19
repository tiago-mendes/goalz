<?php

namespace Database\Factories;

use App\Models\GoalMilestoneAchievement;
use App\Models\GoalMilestoneNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalMilestoneNotification>
 */
class GoalMilestoneNotificationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'goal_milestone_achievement_id' => GoalMilestoneAchievement::factory(),
            'user_id' => User::factory(),
            'seen_at' => null,
        ];
    }

    public function seen(): static
    {
        return $this->state(fn (): array => ['seen_at' => now()]);
    }
}
