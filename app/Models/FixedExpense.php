<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FixedExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring definition, not a transaction. Future occurrences use the month's
 * final calendar day when day_of_month exceeds that month's length, and must
 * respect start_date and is_active.
 */
#[Fillable(['expense_category_id', 'name', 'amount', 'day_of_month', 'start_date', 'is_active'])]
class FixedExpense extends Model
{
    /** @use HasFactory<FixedExpenseFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'day_of_month' => 'integer', 'start_date' => 'date', 'is_active' => 'boolean'];
    }

    public function occurrenceDate(CarbonImmutable $month): ?CarbonImmutable
    {
        $month = $month->startOfMonth();
        $occurrence = $month->setDay(min($this->day_of_month, $month->daysInMonth));

        return $occurrence->toDateString() >= $this->start_date->toDateString() ? $occurrence : null;
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
}
