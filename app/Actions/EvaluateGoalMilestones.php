<?php

namespace App\Actions;

use App\GoalMembershipStatus;
use App\GoalMilestone;
use App\Models\Goal;
use App\Models\GoalMilestoneAchievement;
use App\Models\GoalMilestoneNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class EvaluateGoalMilestones
{
    /** @return Collection<int, GoalMilestoneAchievement> */
    public function handle(Goal $goal, bool $createNotifications = true): Collection
    {
        return DB::transaction(function () use ($goal, $createNotifications): Collection {
            $lockedGoal = Goal::query()->whereKey($goal->id)->lockForUpdate()->first();
            abort_if($lockedGoal === null, 404);
            $lockedGoal->unsetRelation('goalAccountAllocations');

            /** @var Collection<int, GoalMilestoneAchievement> $newAchievements */
            $newAchievements = new Collection;

            foreach (GoalMilestone::cases() as $milestone) {
                if (! $lockedGoal->hasReachedMilestone($milestone)) {
                    continue;
                }

                $achievement = new GoalMilestoneAchievement([
                    'milestone_percentage' => $milestone,
                    'achieved_at' => now(),
                ]);
                $achievement->goal()->associate($lockedGoal);

                try {
                    $achievement->save();
                    $newAchievements->push($achievement);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            if ($createNotifications && $newAchievements->isNotEmpty()) {
                $highestAchievement = $newAchievements->sortByDesc(
                    fn (GoalMilestoneAchievement $achievement): int => $achievement->milestone_percentage->value,
                )->first();
                assert($highestAchievement instanceof GoalMilestoneAchievement);

                $participantIds = $lockedGoal->memberships()
                    ->where('status', GoalMembershipStatus::Accepted)
                    ->pluck('user_id')
                    ->prepend($lockedGoal->user_id)
                    ->unique()
                    ->values();
                $timestamp = now();

                GoalMilestoneNotification::query()->insertOrIgnore(
                    $participantIds->map(fn (int $userId): array => [
                        'goal_milestone_achievement_id' => $highestAchievement->id,
                        'user_id' => $userId,
                        'seen_at' => null,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])->all(),
                );
            }

            return $newAchievements;
        }, attempts: 3);
    }
}
