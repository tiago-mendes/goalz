<?php

namespace App\Actions;

use App\BillingCycle;
use App\CreditCardBill;
use App\Models\CreditCard;
use App\Models\User;

class ProjectCreditCardBill
{
    public function __construct(
        private readonly MaterializeFixedExpensesForMonth $materializeFixedExpenses,
        private readonly CalculateCreditCardBill $calculateCreditCardBill,
    ) {}

    public function handle(User $user, CreditCard $creditCard, BillingCycle $cycle): CreditCardBill
    {
        abort_unless($creditCard->user_id === $user->id, 404);

        foreach ($cycle->intersectingMonths() as $month) {
            $this->materializeFixedExpenses->handle($user, $month->year, $month->month);
        }

        return $this->calculateCreditCardBill->handle($user, $creditCard, $cycle);
    }
}
