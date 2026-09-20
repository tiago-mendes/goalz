<?php

namespace App\Actions;

use App\BudgetMode;
use App\Models\BudgetRule;
use App\Models\ExpenseCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class StopBudget
{
    public function handle(BudgetRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            $category = ExpenseCategory::query()
                ->whereKey($rule->expense_category_id)
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                return;
            }

            $lockedRule = BudgetRule::query()
                ->whereKey($rule->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRule === null) {
                return;
            }

            $current = CarbonImmutable::now()->startOfMonth();
            if ($lockedRule->mode === BudgetMode::Recurring) {
                $lockedRule->ends_month = $current;
                $lockedRule->save();

                return;
            }

            $lockedRule->months()->whereDate('month', '>', $current->toDateString())->delete();
            if ($lockedRule->months()->count() === 0 && ! $lockedRule->months()->whereDate('month', $current->toDateString())->exists()) {
                $lockedRule->delete();
            }
        }, attempts: 3);
    }
}
