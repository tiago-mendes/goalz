<?php

namespace App\Actions;

use App\BudgetMode;
use App\Models\BudgetRule;
use App\Models\ExpenseCategory;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveBudget
{
    /** @param list<string> $selectedMonths */
    public function handle(User $user, ExpenseCategory $category, string $amount, BudgetMode $mode, string $startsMonth, array $selectedMonths = [], ?BudgetRule $existing = null): BudgetRule
    {
        $current = CarbonImmutable::now()->startOfMonth();
        $starts = CarbonImmutable::createFromFormat('!Y-m', $startsMonth);
        /** @var CarbonImmutable $starts */
        $this->validateAmount($amount);
        if ($category->user_id !== $user->id) {
            abort(404);
        }
        if ($existing !== null && $starts->lessThan($current)) {
            throw ValidationException::withMessages(['startsMonth' => 'A budget cannot be changed before the current month.']);
        }

        $months = $this->normalizeMonths($selectedMonths);
        if ($mode === BudgetMode::SelectedMonths && $months === []) {
            throw ValidationException::withMessages(['selectedMonths' => 'Select at least one month.']);
        }
        if ($existing !== null && $existing->user_id !== $user->id) {
            abort(404);
        }

        return DB::transaction(function () use ($user, $category, $amount, $mode, $starts, $months, $existing, $current): BudgetRule {
            $rules = BudgetRule::query()->whereBelongsTo($user)->where('expense_category_id', $category->id)->with('months')->lockForUpdate()->get();
            if ($existing !== null) {
                $existing = $rules->firstWhere('id', $existing->id);
                abort_if($existing === null, 404);
                $this->preserveHistory($existing, $current, $starts->greaterThan($current) ? $starts : $current);
                $rules = $rules->reject(fn (BudgetRule $rule): bool => $rule->is($existing));
            }

            $futureMonths = $mode === BudgetMode::SelectedMonths
            ? ($existing === null ? $months : array_values(collect($months)->filter(fn (CarbonImmutable $month): bool => ! $month->lessThan($current))->all()))
                : [];
            $candidate = ['mode' => $mode, 'starts' => $starts, 'ends' => null, 'months' => $futureMonths];
            $this->ensureNoOverlap($rules, $candidate);

            $rule = $user->budgetRules()->create([
                'expense_category_id' => $category->id,
                'amount' => (string) BigDecimal::of($amount)->toScale(2),
                'mode' => $mode,
                'starts_month' => $mode === BudgetMode::Recurring ? $starts->toDateString() : $starts->toDateString(),
                'ends_month' => null,
            ]);
            if ($mode === BudgetMode::SelectedMonths) {
                $rule->months()->createMany(array_map(fn (CarbonImmutable $month): array => ['month' => $month->toDateString()], $futureMonths));
            }

            return $rule->load('months', 'expenseCategory');
        });
    }

    private function validateAmount(string $amount): void
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/', trim($amount)) !== 1 || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero with up to two decimal places.']);
        }
    }

    /**
     * @param  list<string>  $months
     * @return list<CarbonImmutable>
     */
    private function normalizeMonths(array $months): array
    {
        $normalized = [];
        foreach ($months as $month) {
            if (preg_match('/\A[0-9]{4}-[0-9]{2}\z/', $month) !== 1) {
                throw ValidationException::withMessages(['selectedMonths' => 'Selected months are invalid.']);
            }
            $parsed = CarbonImmutable::createFromFormat('!Y-m', $month);
            /** @var CarbonImmutable $parsed */
            $normalized[] = $parsed;
        }
        $keys = [];
        foreach ($normalized as $month) {
            $keys[] = $month->format('Y-m');
        }
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['selectedMonths' => 'Selected months cannot be duplicated.']);
        }
        usort($normalized, fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->lessThan($right) ? -1 : ($left->equalTo($right) ? 0 : 1));

        return $normalized;
    }

    private function preserveHistory(BudgetRule $rule, CarbonImmutable $current, CarbonImmutable $recurringCutoff): void
    {
        if ($rule->mode === BudgetMode::Recurring) {
            $rule->ends_month = $recurringCutoff->subMonth();
            $rule->save();

            return;
        }

        $rule->months()->whereDate('month', '>=', $current->toDateString())->delete();
        if ($rule->months()->count() === 0) {
            $rule->delete();
        }
    }

    /**
     * @param  Collection<int, BudgetRule>  $rules
     * @param  array{mode: BudgetMode, starts: CarbonImmutable, ends: null, months: list<CarbonImmutable>}  $candidate
     */
    private function ensureNoOverlap(Collection $rules, array $candidate): void
    {
        foreach ($rules as $rule) {
            if ($candidate['mode'] === BudgetMode::SelectedMonths) {
                foreach ($candidate['months'] as $month) {
                    if ($rule->appliesTo($month)) {
                        throw ValidationException::withMessages(['selectedMonths' => 'This budget overlaps another budget for the same category.']);
                    }
                }

                continue;
            }
            if ($rule->mode === BudgetMode::Recurring) {
                if ($rule->ends_month === null || ! $rule->ends_month->startOfMonth()->lessThan($candidate['starts'])) {
                    throw ValidationException::withMessages(['startsMonth' => 'This budget overlaps another budget for the same category.']);
                }
            } elseif ($rule->months->contains(fn ($month): bool => ! $month->month->startOfMonth()->lessThan($candidate['starts']))) {
                throw ValidationException::withMessages(['startsMonth' => 'This budget overlaps another budget for the same category.']);
            }
        }
    }
}
