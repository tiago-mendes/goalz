<?php

namespace App\Models;

use App\GoalMembershipStatus;
use Carbon\CarbonInterface;
use Database\Factories\GoalMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $goal_id
 * @property int $user_id
 * @property int $invited_by_user_id
 * @property GoalMembershipStatus $status
 * @property CarbonInterface|null $responded_at
 */
#[Fillable(['status', 'responded_at'])]
class GoalMembership extends Model
{
    /** @use HasFactory<GoalMembershipFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GoalMembershipStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
