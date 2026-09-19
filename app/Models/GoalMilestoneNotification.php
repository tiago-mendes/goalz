<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\GoalMilestoneNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $goal_milestone_achievement_id
 * @property int $user_id
 * @property CarbonInterface|null $seen_at
 */
#[Fillable(['seen_at'])]
class GoalMilestoneNotification extends Model
{
    /** @use HasFactory<GoalMilestoneNotificationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GoalMilestoneAchievement, $this> */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(GoalMilestoneAchievement::class, 'goal_milestone_achievement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
