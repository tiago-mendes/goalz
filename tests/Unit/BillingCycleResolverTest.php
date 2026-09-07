<?php

namespace Tests\Unit;

use App\BillingCycleResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingCycleResolverTest extends TestCase
{
    public function test_purchase_dates_on_either_side_of_cycle_boundary_resolve_without_an_off_by_one_error(): void
    {
        $resolver = new BillingCycleResolver;

        $endingCycle = $resolver->containing(CarbonImmutable::parse('2026-09-12'), 13, 20);
        $startingCycle = $resolver->containing(CarbonImmutable::parse('2026-09-13'), 13, 20);

        $this->assertSame('2026-08-13', $endingCycle->start->toDateString());
        $this->assertSame('2026-09-12', $endingCycle->end->toDateString());
        $this->assertSame('2026-09-13', $startingCycle->start->toDateString());
        $this->assertSame('2026-10-12', $startingCycle->end->toDateString());
    }

    public function test_cycle_crosses_the_december_to_january_year_boundary(): void
    {
        $cycle = (new BillingCycleResolver)->containing(CarbonImmutable::parse('2027-01-05'), 13, 20);

        $this->assertSame('2026-12-13', $cycle->start->toDateString());
        $this->assertSame('2027-01-12', $cycle->end->toDateString());
        $this->assertSame('2027-01-20', $cycle->dueDate->toDateString());
    }

    #[DataProvider('shortMonthStarts')]
    public function test_cycle_start_days_clamp_to_the_last_day_of_short_months(
        int $day,
        string $date,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $cycle = (new BillingCycleResolver)->containing(CarbonImmutable::parse($date), $day, 20);

        $this->assertSame($expectedStart, $cycle->start->toDateString());
        $this->assertSame($expectedEnd, $cycle->end->toDateString());
    }

    /** @return array<string, array{int, string, string, string}> */
    public static function shortMonthStarts(): array
    {
        return [
            'day 29 in non-leap February' => [29, '2026-02-28', '2026-02-28', '2026-03-28'],
            'day 30 in non-leap February' => [30, '2026-02-28', '2026-02-28', '2026-03-29'],
            'day 31 in non-leap February' => [31, '2026-02-28', '2026-02-28', '2026-03-30'],
            'day 29 in leap February' => [29, '2028-02-29', '2028-02-29', '2028-03-28'],
            'day 30 in leap February' => [30, '2028-02-29', '2028-02-29', '2028-03-29'],
            'day 31 in thirty-day April' => [31, '2026-04-30', '2026-04-30', '2026-05-30'],
        ];
    }

    #[DataProvider('dueDates')]
    public function test_due_date_is_the_first_configured_effective_day_after_cycle_end(
        string $startMonth,
        int $cycleStartDay,
        int $dueDay,
        string $expectedEnd,
        string $expectedDue,
    ): void {
        $cycle = (new BillingCycleResolver)->startingIn(CarbonImmutable::parse($startMonth), $cycleStartDay, $dueDay);

        $this->assertSame($expectedEnd, $cycle->end->toDateString());
        $this->assertSame($expectedDue, $cycle->dueDate->toDateString());
    }

    /** @return array<string, array{string, int, int, string, string}> */
    public static function dueDates(): array
    {
        return [
            'same-month due day after end' => ['2026-08-01', 13, 20, '2026-09-12', '2026-09-20'],
            'next-month due day before end' => ['2026-08-01', 13, 5, '2026-09-12', '2026-10-05'],
            'due 29 clamps in non-leap February' => ['2025-12-01', 31, 29, '2026-01-30', '2026-02-28'],
            'due 30 clamps in non-leap February' => ['2025-12-01', 31, 30, '2026-01-30', '2026-02-28'],
            'due 31 clamps in non-leap February' => ['2026-01-01', 31, 31, '2026-02-27', '2026-02-28'],
            'due 31 clamps in leap February' => ['2028-01-01', 31, 31, '2028-02-28', '2028-02-29'],
        ];
    }

    public function test_selected_bill_month_means_the_month_containing_the_due_date(): void
    {
        $cycle = (new BillingCycleResolver)->dueIn(CarbonImmutable::parse('2026-10-01'), 13, 20);

        $this->assertSame('2026-09-13', $cycle->start->toDateString());
        $this->assertSame('2026-10-12', $cycle->end->toDateString());
        $this->assertSame('2026-10-20', $cycle->dueDate->toDateString());
    }
}
