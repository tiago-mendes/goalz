<?php

namespace App\Actions;

use App\BudgetMode;
use App\Models\BudgetRule;
use Carbon\CarbonImmutable;

class StopBudget
{
    public function handle(BudgetRule $rule): void
    {
        $current = CarbonImmutable::now()->startOfMonth();
        if ($rule->mode === BudgetMode::Recurring) {
            $rule->ends_month = $current;
            $rule->save();

            return;
        }

        $rule->months()->whereDate('month', '>', $current->toDateString())->delete();
        if ($rule->months()->count() === 0 && ! $rule->months()->whereDate('month', $current->toDateString())->exists()) {
            $rule->delete();
        }
    }
}
