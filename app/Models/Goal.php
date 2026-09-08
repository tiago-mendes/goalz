<?php

namespace App\Models;

use App\GoalMembershipStatus;
use App\GoalStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $target_amount
 * @property Carbon|null $target_date
 * @property GoalStatus $status
 */
#[Fillable(['name', 'target_amount', 'target_date'])]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'target_date' => 'date',
            'status' => GoalStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<GoalMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(GoalMembership::class);
    }

    /**
     * @param  Builder<Goal>  $query
     * @return Builder<Goal>
     */
    #[Scope]
    protected function ownedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * @param  Builder<Goal>  $query
     * @return Builder<Goal>
     */
    #[Scope]
    protected function sharedWith(Builder $query, User $user): Builder
    {
        return $query->where('user_id', '!=', $user->id)
            ->whereHas('memberships', fn (Builder $query): Builder => $query
                ->where('user_id', $user->id)
                ->where('status', GoalMembershipStatus::Accepted));
    }

    /**
     * @param  Builder<Goal>  $query
     * @return Builder<Goal>
     */
    #[Scope]
    protected function personalOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id)
            ->whereDoesntHave('memberships', fn (Builder $query): Builder => $query
                ->whereColumn('goal_memberships.user_id', '!=', 'goals.user_id')
                ->where('status', GoalMembershipStatus::Accepted));
    }

    /**
     * @param  Builder<Goal>  $query
     * @return Builder<Goal>
     */
    #[Scope]
    protected function sharedFor(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->whereHas('memberships', fn (Builder $query): Builder => $query
                        ->whereColumn('goal_memberships.user_id', '!=', 'goals.user_id')
                        ->where('status', GoalMembershipStatus::Accepted));
            })->orWhere(function (Builder $query) use ($user): void {
                $query->where('user_id', '!=', $user->id)
                    ->whereHas('memberships', fn (Builder $query): Builder => $query
                        ->where('user_id', $user->id)
                        ->where('status', GoalMembershipStatus::Accepted));
            });
        });
    }

    /**
     * @param  Builder<Goal>  $query
     * @return Builder<Goal>
     */
    #[Scope]
    protected function accessibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id)
                ->orWhereHas('memberships', fn (Builder $query): Builder => $query
                    ->where('user_id', $user->id)
                    ->where('status', GoalMembershipStatus::Accepted));
        });
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /** @return HasMany<GoalAccountAllocation, $this> */
    public function goalAccountAllocations(): HasMany
    {
        return $this->hasMany(GoalAccountAllocation::class);
    }

    public function allocatedAmount(): string
    {
        $total = BigDecimal::of('0.00');
        $allocations = $this->relationLoaded('goalAccountAllocations')
            ? $this->goalAccountAllocations
            : $this->goalAccountAllocations()->get(['amount']);

        foreach ($allocations as $allocation) {
            $total = $total->plus($allocation->amount);
        }

        return (string) $total->toScale(2);
    }

    public function remainingAmount(): string
    {
        return (string) BigDecimal::of($this->target_amount)->minus($this->allocatedAmount())->toScale(2);
    }

    public function overfundedAmount(): string
    {
        $remaining = BigDecimal::of($this->remainingAmount());

        return $remaining->isNegative() ? (string) $remaining->negated()->toScale(2) : '0.00';
    }

    public function progressPercentage(): string
    {
        return (string) BigDecimal::of($this->allocatedAmount())
            ->multipliedBy(100)
            ->dividedBy($this->target_amount, 1, RoundingMode::HalfUp);
    }

    public function visualProgressPercentage(): string
    {
        $progress = BigDecimal::of($this->progressPercentage());

        return (string) ($progress->isGreaterThan(100) ? BigDecimal::of('100.0') : $progress)->toScale(1);
    }
}
