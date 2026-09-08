<?php

namespace App\Reports;

use App\GoalStatus;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;

class GoalsReport
{
    /**
     * @return array{
     *     goals: list<array{key: int, name: string, target: string, allocated: string, remaining: string, progress: string, status: string, overfunded: string}>,
     *     selectedGoal: Goal|null,
     *     selectedSummary: array{target: string, allocated: string, remaining: string, progress: string, status: string, overfunded: string}|null,
     *     fundingSources: list<array{key: int, name: string, type: string, allocated: string, share: string, isActive: bool}>,
     *     fundingTotal: string,
     * }
     */
    public function handle(User $user, ?int $goalId = null): array
    {
        $goals = $this->goals($user);
        $selectedGoal = $goalId === null ? $goals->first() : $goals->firstWhere('id', $goalId);

        $goalProgress = $goals->map(fn (Goal $goal): array => $this->goalProgress($goal))->values()->all();
        $fundingSources = $selectedGoal === null ? [] : $this->fundingSources($selectedGoal, $user);
        $fundingTotal = BigDecimal::zero();

        foreach ($fundingSources as $fundingSource) {
            $fundingTotal = $fundingTotal->plus($fundingSource['allocated']);
        }

        return [
            'goals' => $goalProgress,
            'selectedGoal' => $selectedGoal,
            'selectedSummary' => $selectedGoal === null ? null : $this->goalProgress($selectedGoal),
            'fundingSources' => $fundingSources,
            'fundingTotal' => (string) $fundingTotal->toScale(2),
        ];
    }

    /** @return Collection<int, Goal> */
    public function goals(User $user): Collection
    {
        $goals = $user->goals()
            ->with(['goalAccountAllocations' => fn ($query) => $query
                ->whereHas('account', fn ($query) => $query->whereBelongsTo($user))
                ->with('account')])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $goals->sortBy(fn (Goal $goal): array => [$this->statusOrder($goal->status), mb_strtolower($goal->name), $goal->id])->values();
    }

    /** @return array{key: int, name: string, target: string, allocated: string, remaining: string, progress: string, status: string, overfunded: string} */
    private function goalProgress(Goal $goal): array
    {
        return [
            'key' => $goal->id,
            'name' => $goal->name,
            'target' => (string) BigDecimal::of($goal->target_amount)->toScale(2),
            'allocated' => $goal->allocatedAmount(),
            'remaining' => $goal->remainingAmount(),
            'progress' => $goal->progressPercentage(),
            'status' => $goal->status->label(),
            'overfunded' => $goal->overfundedAmount(),
        ];
    }

    /** @return list<array{key: int, name: string, type: string, allocated: string, share: string, isActive: bool}> */
    private function fundingSources(Goal $goal, User $user): array
    {
        $allocations = $goal->goalAccountAllocations
            ->filter(fn (GoalAccountAllocation $allocation): bool => $allocation->account !== null && $allocation->account->user_id === $user->id)
            ->values();
        $total = BigDecimal::zero();

        foreach ($allocations as $allocation) {
            $total = $total->plus($allocation->amount);
        }

        return $allocations->map(fn (GoalAccountAllocation $allocation): array => [
            'key' => $allocation->id,
            'name' => $allocation->account->name,
            'type' => $allocation->account->type->label(),
            'allocated' => (string) BigDecimal::of($allocation->amount)->toScale(2),
            'share' => $this->percentage(BigDecimal::of($allocation->amount), $total),
            'isActive' => $allocation->account->is_active,
        ])->all();
    }

    private function percentage(BigDecimal $amount, BigDecimal $total): string
    {
        if ($total->isZero()) {
            return '0.0';
        }

        return (string) $amount->multipliedBy(100)->dividedBy($total, 1, RoundingMode::HalfUp);
    }

    private function statusOrder(GoalStatus $status): int
    {
        return match ($status) {
            GoalStatus::Active => 0,
            GoalStatus::Paused => 1,
            GoalStatus::Completed => 2,
        };
    }
}
