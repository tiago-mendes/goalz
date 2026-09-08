<?php

namespace App\Models;

use App\BudgetMode;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $amount
 * @property BudgetMode $mode
 * @property CarbonImmutable $starts_month
 * @property CarbonImmutable|null $ends_month
 */
#[Fillable(['user_id', 'expense_category_id', 'amount', 'mode', 'starts_month', 'ends_month'])]
class BudgetRule extends Model
{
    /** @use HasFactory<BudgetRuleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'mode' => BudgetMode::class, 'starts_month' => 'date', 'ends_month' => 'date'];
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

    /** @return HasMany<BudgetRuleMonth, $this> */
    public function months(): HasMany
    {
        return $this->hasMany(BudgetRuleMonth::class);
    }

    public function appliesTo(CarbonImmutable $month): bool
    {
        $month = $month->startOfMonth();

        if ($this->mode === BudgetMode::Recurring) {
            return ! $this->starts_month->startOfMonth()->greaterThan($month)
                && ($this->ends_month === null || ! $this->ends_month->startOfMonth()->lessThan($month));
        }

        return $this->months->contains(fn (BudgetRuleMonth $selectedMonth): bool => $selectedMonth->month->isSameMonth($month));
    }
}
