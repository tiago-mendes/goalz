<?php

namespace App\Models;

use App\GoalMilestone;
use Database\Factories\GoalRewardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $goal_id
 * @property GoalMilestone $milestone_percentage
 * @property string $title
 * @property string|null $description
 */
#[Fillable(['milestone_percentage', 'title', 'description'])]
class GoalReward extends Model
{
    /** @use HasFactory<GoalRewardFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'milestone_percentage' => GoalMilestone::class,
        ];
    }

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
