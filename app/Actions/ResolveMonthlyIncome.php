<?php

namespace App\Actions;

use App\Models\MonthlyIncome;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ResolveMonthlyIncome
{
    public function handle(User $user, int $year, int $month): ?MonthlyIncome
    {
        $period = ['year' => $year, 'month' => $month];
        $income = $user->monthlyIncomes()->where($period)->first();

        if ($income === null && $user->default_monthly_income !== null) {
            Gate::forUser($user)->authorize('create', MonthlyIncome::class);
            $income = $user->monthlyIncomes()->firstOrCreate($period, ['amount' => $user->default_monthly_income]);
        }

        return $income;
    }
}
