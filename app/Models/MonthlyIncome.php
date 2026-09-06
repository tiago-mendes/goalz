<?php

namespace App\Models;

use Database\Factories\MonthlyIncomeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['year', 'month', 'amount'])]
class MonthlyIncome extends Model
{
    /** @use HasFactory<MonthlyIncomeFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
