<?php

namespace App;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

final class BillingCycleResolver
{
    public function containing(CarbonImmutable $date, int $cycleStartDay, int $dueDay): BillingCycle
    {
        $this->guardDays($cycleStartDay, $dueDay);
        $month = $date->startOfMonth();
        $thisMonthStart = $this->effectiveDate($month, $cycleStartDay);
        $startMonth = $date->lessThan($thisMonthStart) ? $month->subMonth() : $month;

        return $this->startingIn($startMonth, $cycleStartDay, $dueDay);
    }

    public function current(int $cycleStartDay, int $dueDay, CarbonImmutable $today): BillingCycle
    {
        return $this->containing($today, $cycleStartDay, $dueDay);
    }

    public function dueIn(CarbonImmutable $dueMonth, int $cycleStartDay, int $dueDay): BillingCycle
    {
        $this->guardDays($cycleStartDay, $dueDay);
        $dueMonth = $dueMonth->startOfMonth();

        for ($offset = 1; $offset <= 3; $offset++) {
            $cycle = $this->startingIn($dueMonth->subMonths($offset), $cycleStartDay, $dueDay);

            if ($cycle->dueDate->format('Y-m') === $dueMonth->format('Y-m')) {
                return $cycle;
            }
        }

        throw new LogicException('Unable to resolve a billing cycle for the selected due month.');
    }

    public function startingIn(CarbonImmutable $startMonth, int $cycleStartDay, int $dueDay): BillingCycle
    {
        $this->guardDays($cycleStartDay, $dueDay);
        $start = $this->effectiveDate($startMonth->startOfMonth(), $cycleStartDay);
        $end = $this->effectiveDate($startMonth->addMonth()->startOfMonth(), $cycleStartDay)->subDay();
        $dueDate = $this->effectiveDate($end->startOfMonth(), $dueDay);

        if (! $dueDate->greaterThan($end)) {
            $dueDate = $this->effectiveDate($end->startOfMonth()->addMonth(), $dueDay);
        }

        return new BillingCycle($start, $end, $dueDate);
    }

    private function effectiveDate(CarbonImmutable $month, int $configuredDay): CarbonImmutable
    {
        return $month->setDay(min($configuredDay, $month->daysInMonth))->startOfDay();
    }

    private function guardDays(int $cycleStartDay, int $dueDay): void
    {
        if ($cycleStartDay < 1 || $cycleStartDay > 31 || $dueDay < 1 || $dueDay > 31) {
            throw new InvalidArgumentException('Billing cycle days must be between 1 and 31.');
        }
    }
}
