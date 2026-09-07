<?php

namespace App;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Collection;

final readonly class CreditCardBill
{
    /** @param Collection<int, Expense> $expenses */
    public function __construct(
        public BillingCycle $cycle,
        public string $total,
        public Collection $expenses,
    ) {}
}
