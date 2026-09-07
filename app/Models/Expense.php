<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'expense_category_id', 'amount', 'expense_date', 'description'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expense_date' => 'date', 'deleted_by_user_at' => 'datetime',
            'occurrence_year' => 'integer', 'occurrence_month' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /** @return BelongsTo<FixedExpense, $this> */
    public function fixedExpense(): BelongsTo
    {
        return $this->belongsTo(FixedExpense::class);
    }

    /** @param Builder<Expense> $query */
    #[Scope]
    protected function forMonth(Builder $query, CarbonImmutable $month): void
    {
        $query->where(function (Builder $query) use ($month): void {
            $query->where(function (Builder $query) use ($month): void {
                $query->whereNull('fixed_expense_id')->whereBetween('expense_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()]);
            })->orWhere(function (Builder $query) use ($month): void {
                $query->whereNotNull('fixed_expense_id')->where('occurrence_year', $month->year)->where('occurrence_month', $month->month);
            });
        });
    }
}
