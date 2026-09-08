<?php

namespace App\Reports;

use App\Models\BudgetRule;
use App\Models\ExpenseCategory;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class BudgetReport
{
    /**
     * @return array{month: string, rows: list<array{key: int, category: ExpenseCategory, budget: string, expenses: string, remaining: string, usage: string, status: string, overBudget: string}>, totalBudget: string, budgetedSpending: string, remaining: string, overallUsage: string}
     */
    public function handle(User $user, CarbonImmutable $month): array
    {
        $period = $month->startOfMonth();
        $rules = $user->budgetRules()->with(['expenseCategory', 'months'])->get()
            ->filter(fn (BudgetRule $rule): bool => $rule->appliesTo($period));
        $expenses = $user->expenses()->forMonth($period)->whereNull('deleted_by_user_at')
            ->select(['expense_category_id', 'amount'])->get()->groupBy('expense_category_id');
        $rows = [];
        $totalBudget = BigDecimal::zero();
        $budgetedSpending = BigDecimal::zero();

        foreach ($rules as $rule) {
            $budget = BigDecimal::of($rule->amount);
            $spent = BigDecimal::zero();
            foreach ($expenses->get($rule->expense_category_id, collect()) as $expense) {
                $spent = $spent->plus($expense->amount);
            }
            $remaining = $budget->minus($spent);
            $totalBudget = $totalBudget->plus($budget);
            $budgetedSpending = $budgetedSpending->plus($spent);
            $rows[] = [
                'key' => $rule->id,
                'category' => $rule->expenseCategory,
                'budget' => (string) $budget->toScale(2),
                'expenses' => (string) $spent->toScale(2),
                'remaining' => (string) $remaining->toScale(2),
                'usage' => $this->percentage($spent, $budget),
                'status' => $spent->isGreaterThan($budget) ? 'Over budget' : ($spent->isEqualTo($budget) ? 'Budget reached' : 'On track'),
                'overBudget' => (string) ($spent->isGreaterThan($budget) ? $spent->minus($budget) : BigDecimal::zero())->toScale(2),
            ];
        }

        usort($rows, fn (array $left, array $right): int => BigDecimal::of((string) $right['usage'])->compareTo(BigDecimal::of((string) $left['usage'])) ?: strcasecmp($left['category']->name, $right['category']->name));

        return [
            'month' => $period->format('Y-m'),
            'rows' => $rows,
            'totalBudget' => (string) $totalBudget->toScale(2),
            'budgetedSpending' => (string) $budgetedSpending->toScale(2),
            'remaining' => (string) $totalBudget->minus($budgetedSpending)->toScale(2),
            'overallUsage' => $this->percentage($budgetedSpending, $totalBudget),
        ];
    }

    private function percentage(BigDecimal $amount, BigDecimal $total): string
    {
        if ($total->isZero()) {
            return '0.0';
        }

        return (string) $amount->multipliedBy(100)->dividedBy($total, 1, RoundingMode::HalfUp);
    }
}
