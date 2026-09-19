<?php

namespace App\Actions;

use App\GoalMilestone;
use App\Models\Goal;
use App\Models\GoalReward;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveGoalReward
{
    public function handle(
        User $owner,
        int $goalId,
        GoalMilestone $milestone,
        string $title,
        ?string $description,
        ?int $rewardId = null,
    ): GoalReward {
        return DB::transaction(function () use ($owner, $goalId, $milestone, $title, $description, $rewardId): GoalReward {
            $goal = Goal::query()->whereKey($goalId)->whereBelongsTo($owner)->lockForUpdate()->first();
            abort_if($goal === null, 404);

            $reward = $rewardId === null
                ? new GoalReward
                : GoalReward::query()
                    ->whereKey($rewardId)
                    ->whereBelongsTo($goal)
                    ->where('milestone_percentage', $milestone->value)
                    ->lockForUpdate()
                    ->first();
            abort_if($reward === null, 404);

            if ($goal->milestoneAchievements()->where('milestone_percentage', $milestone->value)->exists()) {
                throw ValidationException::withMessages([
                    'rewardMilestone' => 'Rewards cannot be changed after their milestone is achieved.',
                ]);
            }

            $reward->goal()->associate($goal);
            $reward->milestone_percentage = $milestone;
            $reward->title = $title;
            $reward->description = $description;

            try {
                $reward->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'rewardMilestone' => 'This milestone already has a reward.',
                ]);
            }

            return $reward;
        }, attempts: 3);
    }
}
