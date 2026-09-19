<?php

namespace App;

use App\Models\Goal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class GoalFundingPlan
{
    public function __construct(
        public string $remainingAmount,
        public ?int $monthsRemaining,
        public ?string $requiredPerMonth,
        public string $deadlineState,
    ) {}

    public static function forGoal(Goal $goal): self
    {
        $remaining = BigDecimal::of($goal->target_amount)->minus($goal->allocatedAmount());

        if ($remaining->isNegativeOrZero()) {
            return new self('0.00', null, null, 'funded');
        }

        if ($goal->target_date === null) {
            return new self((string) $remaining->toScale(2), null, null, 'no_target_date');
        }

        $today = now()->startOfDay();
        $targetDate = $goal->target_date->copy()->startOfDay();

        if ($targetDate->isSameDay($today)) {
            return new self((string) $remaining->toScale(2), null, null, 'due_today');
        }

        if ($targetDate->isBefore($today)) {
            return new self((string) $remaining->toScale(2), null, null, 'overdue');
        }

        $monthsRemaining = (($targetDate->year * 12) + $targetDate->month) - (($today->year * 12) + $today->month);
        $monthsRemaining = max($monthsRemaining, 1);
        $requiredPerMonth = $remaining->dividedBy($monthsRemaining, 2, RoundingMode::HalfUp);

        return new self(
            (string) $remaining->toScale(2),
            $monthsRemaining,
            (string) $requiredPerMonth->toScale(2),
            'active',
        );
    }
}
