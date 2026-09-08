<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveGoalAccountAllocation
{
    public function handle(User $user, int $goalId, int $accountId, string $amount, ?int $allocationId = null): GoalAccountAllocation
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($user, $goalId, $accountId, $amount, $allocationId): GoalAccountAllocation {
            $account = Account::query()->whereKey($accountId)->whereBelongsTo($user)->lockForUpdate()->first();
            abort_if($account === null, 404);

            $goal = Goal::query()->whereKey($goalId)->accessibleTo($user)->lockForUpdate()->first();
            abort_if($goal === null, 404);

            $allocation = $allocationId === null
                ? new GoalAccountAllocation
                : GoalAccountAllocation::query()
                    ->whereKey($allocationId)
                    ->whereBelongsTo($goal)
                    ->whereBelongsTo($account)
                    ->lockForUpdate()
                    ->first();
            abort_if($allocation === null, 404);

            if (! $allocation->exists && GoalAccountAllocation::query()->whereBelongsTo($goal)->whereBelongsTo($account)->exists()) {
                throw ValidationException::withMessages(['accountId' => 'This account already funds the goal.']);
            }

            $requestedAmount = BigDecimal::of($amount)->toScale(2);
            $currentAmount = BigDecimal::of($allocation->exists ? $allocation->amount : '0.00');

            if ($requestedAmount->isGreaterThan($currentAmount)) {
                $this->validateIncreaseCapacity($account, $goal, $requestedAmount, $allocation->exists ? $allocation->id : null);
            }

            $allocation->goal()->associate($goal);
            $allocation->account()->associate($account);
            $allocation->amount = (string) $requestedAmount;

            try {
                $allocation->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['accountId' => 'This account already funds the goal.']);
            }

            return $allocation;
        }, attempts: 3);
    }

    private function validateAmount(string $amount): void
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/', $amount) !== 1 || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount greater than zero up to 9999999999999.99 using at most two decimal places.',
            ]);
        }
    }

    private function validateIncreaseCapacity(Account $account, Goal $goal, BigDecimal $requestedAmount, ?int $allocationId): void
    {
        if (! $account->is_active) {
            throw ValidationException::withMessages(['amount' => 'Inactive accounts cannot receive new or increased allocations.']);
        }

        $accountAllocatedElsewhere = $this->sumAllocations(
            GoalAccountAllocation::query()->whereBelongsTo($account)->when($allocationId, fn ($query) => $query->where('id', '!=', $allocationId))->get(['amount']),
        );
        $goalAllocatedElsewhere = $this->sumAllocations(
            GoalAccountAllocation::query()->whereBelongsTo($goal)->when($allocationId, fn ($query) => $query->where('id', '!=', $allocationId))->get(['amount']),
        );
        $accountMaximum = BigDecimal::of($account->current_balance)->minus($accountAllocatedElsewhere);
        $goalMaximum = BigDecimal::of($goal->target_amount)->minus($goalAllocatedElsewhere);
        $maximum = $accountMaximum->isLessThan($goalMaximum) ? $accountMaximum : $goalMaximum;

        if ($requestedAmount->isGreaterThan($maximum)) {
            throw ValidationException::withMessages([
                'amount' => 'The amount exceeds the available account balance or the goal remaining target.',
            ]);
        }
    }

    /** @param Collection<int, GoalAccountAllocation> $allocations */
    private function sumAllocations(Collection $allocations): BigDecimal
    {
        $total = BigDecimal::of('0.00');

        foreach ($allocations as $allocation) {
            $total = $total->plus($allocation->amount);
        }

        return $total;
    }
}
