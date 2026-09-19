<?php

namespace App\Actions;

use App\Models\Goal;
use App\Models\GoalReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteGoalReward
{
    public function handle(User $owner, int $goalId, int $rewardId): void
    {
        DB::transaction(function () use ($owner, $goalId, $rewardId): void {
            $goal = Goal::query()->whereKey($goalId)->whereBelongsTo($owner)->lockForUpdate()->first();
            abort_if($goal === null, 404);

            $reward = GoalReward::query()->whereKey($rewardId)->whereBelongsTo($goal)->lockForUpdate()->first();
            abort_if($reward === null, 404);

            if ($goal->milestoneAchievements()->where('milestone_percentage', $reward->milestone_percentage->value)->exists()) {
                throw ValidationException::withMessages([
                    'rewardMilestone' => 'Rewards cannot be removed after their milestone is achieved.',
                ]);
            }

            $reward->delete();
        }, attempts: 3);
    }
}
