<?php

namespace App;

use App\Models\CreditCardBillPeriod;

final readonly class ResolvedCreditCardBillPeriod
{
    public function __construct(
        public BillingCycle $cycle,
        public ?CreditCardBillPeriod $override,
    ) {}

    public function isCustom(): bool
    {
        return $this->override !== null;
    }
}
