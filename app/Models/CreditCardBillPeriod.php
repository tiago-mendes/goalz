<?php

namespace App\Models;

use Database\Factories\CreditCardBillPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['due_year', 'due_month', 'start_date', 'end_date'])]
class CreditCardBillPeriod extends Model
{
    /** @use HasFactory<CreditCardBillPeriodFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_year' => 'integer',
            'due_month' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }
}
