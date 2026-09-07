<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountBalanceSnapshot>
 */
class AccountBalanceSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'balance' => '100.00',
            'recorded_at' => now(),
        ];
    }
}
