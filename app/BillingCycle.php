<?php

namespace App;

use Carbon\CarbonImmutable;

final readonly class BillingCycle
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $dueDate,
    ) {}

    /** @return list<CarbonImmutable> */
    public function intersectingMonths(): array
    {
        $months = [];
        $month = $this->start->startOfMonth();
        $lastMonth = $this->end->startOfMonth();

        while ($month->lessThanOrEqualTo($lastMonth)) {
            $months[] = $month;
            $month = $month->addMonth();
        }

        return $months;
    }
}
