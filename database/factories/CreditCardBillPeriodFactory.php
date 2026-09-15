<?php

namespace Database\Factories;

use App\Models\CreditCard;
use App\Models\CreditCardBillPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCardBillPeriod>
 */
class CreditCardBillPeriodFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'credit_card_id' => CreditCard::factory(),
            'due_year' => 2026,
            'due_month' => 9,
            'start_date' => '2026-08-11',
            'end_date' => '2026-09-12',
        ];
    }
}
