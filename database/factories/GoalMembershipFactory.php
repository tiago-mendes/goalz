<?php

namespace Database\Factories;

use App\GoalMembershipStatus;
use App\Models\Goal;
use App\Models\GoalMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalMembership>
 */
class GoalMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'user_id' => User::factory(),
            'invited_by_user_id' => function (array $attributes): int {
                $goal = Goal::query()->find($attributes['goal_id']);
                assert($goal instanceof Goal);

                return $goal->user_id;
            },
            'status' => GoalMembershipStatus::Pending,
            'responded_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => GoalMembershipStatus::Accepted,
            'responded_at' => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (): array => [
            'status' => GoalMembershipStatus::Declined,
            'responded_at' => now(),
        ]);
    }
}
