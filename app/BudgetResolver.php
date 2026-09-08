<?php

namespace App;

use App\Models\BudgetRule;
use App\Models\ExpenseCategory;
use App\Models\User;
use Carbon\CarbonImmutable;

class BudgetResolver
{
    public function forMonth(User $user, ExpenseCategory|int $category, CarbonImmutable|string $month): ?BudgetRule
    {
        $categoryId = $category instanceof ExpenseCategory ? $category->id : $category;
        $period = is_string($month) ? CarbonImmutable::createFromFormat('!Y-m', $month) : $month;
        $monthDate = $period->startOfMonth()->toDateString();

        return BudgetRule::query()->whereBelongsTo($user)->where('expense_category_id', $categoryId)
            ->where(function ($query) use ($monthDate): void {
                $query->where(function ($query) use ($monthDate): void {
                    $query->where('mode', BudgetMode::Recurring->value)->whereDate('starts_month', '<=', $monthDate)
                        ->where(function ($query) use ($monthDate): void {
                            $query->whereNull('ends_month')->orWhereDate('ends_month', '>=', $monthDate);
                        });
                })->orWhere(function ($query) use ($monthDate): void {
                    $query->where('mode', BudgetMode::SelectedMonths->value)->whereHas('months', fn ($query) => $query->whereDate('month', $monthDate));
                });
            })->with('months')->first();
    }
}
