<?php

namespace App;

use App\Models\CreditCard;
use Carbon\CarbonImmutable;

final readonly class CreditCardBillPeriodResolver
{
    public function __construct(private BillingCycleResolver $billingCycleResolver) {}

    public function resolve(CreditCard $creditCard, CarbonImmutable $dueMonth): ResolvedCreditCardBillPeriod
    {
        $calculated = $this->billingCycleResolver->dueInFor($creditCard, $dueMonth);
        $override = $creditCard->billPeriods()
            ->where('due_year', $calculated->dueDate->year)
            ->where('due_month', $calculated->dueDate->month)
            ->first();

        if ($override === null) {
            return new ResolvedCreditCardBillPeriod($calculated, null);
        }

        return new ResolvedCreditCardBillPeriod(
            new BillingCycle(
                CarbonImmutable::parse((string) $override->start_date),
                CarbonImmutable::parse((string) $override->end_date),
                $calculated->dueDate,
            ),
            $override,
        );
    }
}
