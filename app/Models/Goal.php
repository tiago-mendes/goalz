<?php

namespace App\Models;

use App\GoalStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
