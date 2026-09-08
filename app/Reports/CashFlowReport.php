<?php

namespace App\Reports;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class CashFlowReport
{
    /**
     * @return array{
     *     months: list<array{key: string, label: string, income: string|null, expenses: string, remaining: string|null, recurring: string, manual: string}>,
     *     categories: list<array{key: string, name: string, amount: string, percentage: string, icon: string, color: string}>,
     *     categoryEvolution: list<array{key: string, label: string, amount: string}>,
     *     selectedCategory: ExpenseCategory|null,
     * }
     */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $categoryId = null): array
    {
        $months = [];
        $month = $from->startOfMonth();

        while ($month->lessThanOrEqualTo($to->startOfMonth())) {
            $key = $month->format('Y-m');
            $months[$key] = [
                'key' => $key,
                'label' => $month->format('F Y'),
                'income' => null,
                'expenses' => BigDecimal::zero(),
                'remaining' => null,
                'recurring' => BigDecimal::zero(),
                'manual' => BigDecimal::zero(),
            ];
            $month = $month->addMonth();
        }

        $expenses = $this->expenses($user, $from, $to);
        $categoryTotals = [];

        foreach ($expenses as $expense) {
            $monthKey = $expense->fixed_expense_id === null
                ? $expense->expense_date->format('Y-m')
                : sprintf('%04d-%02d', $expense->occurrence_year, $expense->occurrence_month);
            $amount = BigDecimal::of($expense->amount);

            if (! isset($months[$monthKey])) {
                continue;
            }

            $months[$monthKey]['expenses'] = $months[$monthKey]['expenses']->plus($amount);
            $months[$monthKey][$expense->fixed_expense_id === null ? 'manual' : 'recurring'] = $months[$monthKey][$expense->fixed_expense_id === null ? 'manual' : 'recurring']->plus($amount);

            $categoryKey = (string) $expense->expense_category_id;
            $categoryTotals[$categoryKey] = ($categoryTotals[$categoryKey] ?? BigDecimal::zero())->plus($amount);
        }

        foreach ($user->monthlyIncomes()
            ->where(function ($query) use ($from): void {
                $query->where('year', '>', $from->year)
                    ->orWhere(function ($query) use ($from): void {
                        $query->where('year', $from->year)->where('month', '>=', $from->month);
                    });
            })
            ->where(function ($query) use ($to): void {
                $query->where('year', '<', $to->year)
                    ->orWhere(function ($query) use ($to): void {
                        $query->where('year', $to->year)->where('month', '<=', $to->month);
                    });
            })->get(['year', 'month', 'amount']) as $income) {
            $key = sprintf('%04d-%02d', $income->year, $income->month);
            if (isset($months[$key])) {
                $months[$key]['income'] = (string) BigDecimal::of($income->amount)->toScale(2);
                $months[$key]['remaining'] = (string) BigDecimal::of($income->amount)->minus($months[$key]['expenses'])->toScale(2);
            }
        }

        $totalExpenses = array_reduce($months, fn (BigDecimal $total, array $month): BigDecimal => $total->plus($month['expenses']), BigDecimal::zero());
        $categories = [];
        $categoryModels = $user->expenseCategories()->get()->keyBy('id');

        foreach ($categoryTotals as $categoryIdKey => $amount) {
            $category = $categoryModels->get((int) $categoryIdKey);
            $categories[] = [
                'key' => $categoryIdKey,
                'name' => $category?->name ?? 'Category unavailable',
                'amount' => (string) $amount->toScale(2),
                'percentage' => $this->percentage($amount, $totalExpenses),
                'icon' => $category?->safeIcon() ?? ExpenseCategory::DEFAULT_ICON,
                'color' => $category?->safeColor() ?? ExpenseCategory::DEFAULT_COLOR,
            ];
        }

        usort($categories, fn (array $left, array $right): int => BigDecimal::of($right['amount'])->compareTo(BigDecimal::of($left['amount'])) ?: strcasecmp($left['name'], $right['name']));

        $selectedCategory = $categoryId === null ? null : $categoryModels->get($categoryId);
        $categoryEvolution = array_map(function (array $month) use ($selectedCategory, $expenses): array {
            $amount = BigDecimal::zero();

            if ($selectedCategory !== null) {
                foreach ($expenses as $expense) {
                    $expenseMonth = $expense->fixed_expense_id === null
                        ? $expense->expense_date->format('Y-m')
                        : sprintf('%04d-%02d', $expense->occurrence_year, $expense->occurrence_month);
                    if ($expenseMonth === $month['key'] && $expense->expense_category_id === $selectedCategory->id) {
                        $amount = $amount->plus($expense->amount);
                    }
                }
            }

            return ['key' => $month['key'], 'label' => $month['label'], 'amount' => (string) $amount->toScale(2)];
        }, array_values($months));

        return [
            'months' => array_map(fn (array $month): array => [
                ...$month,
                'expenses' => (string) $month['expenses']->toScale(2),
                'recurring' => (string) $month['recurring']->toScale(2),
                'manual' => (string) $month['manual']->toScale(2),
            ], array_values($months)),
            'categories' => $categories,
            'categoryEvolution' => $categoryEvolution,
            'selectedCategory' => $selectedCategory,
        ];
    }

    /** @return Collection<int, Expense> */
    private function expenses(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $user->expenses()
            ->whereNull('deleted_by_user_at')
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($query) use ($from, $to): void {
                    $query->whereNull('fixed_expense_id')->whereBetween('expense_date', [$from->startOfMonth()->toDateString(), $to->endOfMonth()->toDateString()]);
                })->orWhere(function ($query) use ($from, $to): void {
                    $query->whereNotNull('fixed_expense_id')
                        ->where(function ($query) use ($from): void {
                            $query->where('occurrence_year', '>', $from->year)
                                ->orWhere(function ($query) use ($from): void {
                                    $query->where('occurrence_year', $from->year)->where('occurrence_month', '>=', $from->month);
                                });
                        })->where(function ($query) use ($to): void {
                            $query->where('occurrence_year', '<', $to->year)
                                ->orWhere(function ($query) use ($to): void {
                                    $query->where('occurrence_year', $to->year)->where('occurrence_month', '<=', $to->month);
                                });
                        });
                });
            })->get(['id', 'expense_category_id', 'fixed_expense_id', 'occurrence_year', 'occurrence_month', 'amount', 'expense_date']);
    }

    private function percentage(BigDecimal $amount, BigDecimal $total): string
    {
        if ($total->isZero()) {
            return '0.0';
        }

        return (string) $amount->multipliedBy(100)->dividedBy($total, 1, RoundingMode::HalfUp);
    }
}
