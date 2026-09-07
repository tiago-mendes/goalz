<?php

namespace App\Actions;

use App\Models\Expense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class MaterializeFixedExpensesForMonth
{
    public function handle(User $user, int $year, int $month): void
    {
        Validator::make(['year' => $year, 'month' => $month], [
            'year' => ['required', 'integer', 'between:1000,9999'],
            'month' => ['required', 'integer', 'between:1,12'],
        ])->validate();
        Gate::forUser($user)->authorize('create', Expense::class);
        $period = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $templates = $user->fixedExpenses()->where('is_active', true)
            ->whereDate('start_date', '<=', $period->endOfMonth()->toDateString())
            ->whereHas('expenseCategory', fn (Builder $query): Builder => $query->where('user_id', $user->id))->get();

        foreach ($templates as $template) {
            $date = $template->occurrenceDate($period);
            $identity = ['fixed_expense_id' => $template->id, 'occurrence_year' => $year, 'occurrence_month' => $month];
            if ($date === null || $user->expenses()->where($identity)->exists()) {
                continue;
            }

            try {
                DB::transaction(function () use ($user, $template, $identity, $date): void {
                    $expense = $user->expenses()->make([
                        'expense_category_id' => $template->expense_category_id,
                        'name' => $template->name, 'amount' => $template->amount,
                        'expense_date' => $date->toDateString(), 'description' => null,
                    ]);
                    $expense->fixed_expense_id = $identity['fixed_expense_id'];
                    $expense->occurrence_year = $identity['occurrence_year'];
                    $expense->occurrence_month = $identity['occurrence_month'];
                    $expense->save();
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (! $user->expenses()->where($identity)->exists()) {
                    throw $exception;
                }
            }
        }
    }
}
