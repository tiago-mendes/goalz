<?php

namespace App\Reports;

use App\GoalMembershipStatus;
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
     *     selectedSummary: array{key: int, name: string, target: string, allocated: string, remaining: string, progress: string, status: string, overfunded: string}|null,
     *     fundingSources: list<array{key: string, name: string, type: string, allocated: string, share: string, isActive: bool|null, group: string}>,
     *     fundingTotal: string,
     * }
     */
    public function handle(User $user, ?int $goalId = null): array
    {
        $goals = $this->goals($user);
        $selectedGoal = $goalId === null ? $goals->first() : $goals->firstWhere('id', $goalId);

        $goalProgress = array_values($goals->map(fn (Goal $goal): array => $this->goalProgress($goal))->all());
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
        $goals = Goal::query()->accessibleTo($user)
            ->with(['goalAccountAllocations:id,goal_id,amount', 'owner:id,name'])
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

    /** @return list<array{key: string, name: string, type: string, allocated: string, share: string, isActive: bool|null, group: string}> */
    private function fundingSources(Goal $goal, User $user): array
    {
        $total = BigDecimal::of($goal->allocatedAmount());
        $ownAllocations = GoalAccountAllocation::query()
            ->whereBelongsTo($goal)
            ->whereHas('account', fn ($query) => $query->whereBelongsTo($user))
            ->with(['account' => fn ($query) => $query->whereBelongsTo($user)])
            ->orderBy('id')
            ->get();
        /** @var list<array{key: string, name: string, type: string, allocated: string, share: string, isActive: bool|null, group: string}> $sources */
        $sources = array_values($ownAllocations->map(fn (GoalAccountAllocation $allocation): array => [
            'key' => 'account-'.$allocation->id,
            'name' => $allocation->account->name,
            'type' => $allocation->account->type->label(),
            'allocated' => (string) BigDecimal::of($allocation->amount)->toScale(2),
            'share' => $this->percentage(BigDecimal::of($allocation->amount), $total),
            'isActive' => $allocation->account->is_active,
            'group' => 'Your Accounts',
        ])->all());

        $participantIds = $goal->memberships()
            ->where('status', GoalMembershipStatus::Accepted)
            ->pluck('user_id')
            ->prepend($goal->user_id)
            ->unique()
            ->reject(fn (int $userId): bool => $userId === $user->id)
            ->values();
        $contributionTotals = GoalAccountAllocation::query()
            ->join('accounts', 'accounts.id', '=', 'goal_account_allocations.account_id')
            ->where('goal_account_allocations.goal_id', $goal->id)
            ->whereIn('accounts.user_id', $participantIds)
            ->selectRaw('accounts.user_id, CAST(SUM(goal_account_allocations.amount) AS CHAR) as contribution_total')
            ->groupBy('accounts.user_id')
            ->pluck('contribution_total', 'accounts.user_id');

        User::query()->whereIn('id', $participantIds)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->each(function (User $member) use ($contributionTotals, &$sources, $total): void {
                $amount = BigDecimal::of($contributionTotals->get($member->id, '0.00'));
                $sources[] = [
                    'key' => 'member-'.$member->id,
                    'name' => $member->name,
                    'type' => 'Member contribution',
                    'allocated' => (string) $amount->toScale(2),
                    'share' => $this->percentage($amount, $total),
                    'isActive' => null,
                    'group' => 'Other Members',
                ];
            });

        return $sources;
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
