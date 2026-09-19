<?php

namespace App\Models;

use App\GoalMilestone;
use Carbon\CarbonInterface;
use Database\Factories\GoalMilestoneAchievementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $goal_id
 * @property GoalMilestone $milestone_percentage
 * @property CarbonInterface $achieved_at
 */
#[Fillable(['milestone_percentage', 'achieved_at'])]
class GoalMilestoneAchievement extends Model
{
    /** @use HasFactory<GoalMilestoneAchievementFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'milestone_percentage' => GoalMilestone::class,
            'achieved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** @return HasMany<GoalMilestoneNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(GoalMilestoneNotification::class);
    }

    public function reward(): ?GoalReward
    {
        if (! $this->relationLoaded('goal')) {
            $this->load('goal.rewards');
        } elseif (! $this->goal->relationLoaded('rewards')) {
            $this->goal->load('rewards');
        }

        return $this->goal->rewards->first(
            fn (GoalReward $reward): bool => $reward->milestone_percentage === $this->milestone_percentage,
        );
    }
}
