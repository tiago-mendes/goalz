<?php

namespace App\Actions;

use App\BillingCycle;
use App\CreditCardBill;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class CalculateCreditCardBill
{
    public function handle(User $user, CreditCard $creditCard, BillingCycle $cycle): CreditCardBill
    {
        abort_unless($creditCard->user_id === $user->id, 404);

        $expenses = $user->expenses()
            ->where('credit_card_id', $creditCard->id)
            ->whereNull('deleted_by_user_at')
            ->whereDate('expense_date', '>=', $cycle->start->toDateString())
            ->whereDate('expense_date', '<=', $cycle->end->toDateString())
            ->with(['expenseCategory' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        $total = $this->sumExpenses($expenses);

        $cutoff = CarbonImmutable::today();
        $totalSoFar = BigDecimal::of('0.00');

        if ($cutoff->greaterThanOrEqualTo($cycle->start)) {
            $cutoff = $cutoff->lessThan($cycle->end) ? $cutoff : $cycle->end;
            $totalSoFar = $this->sumExpenses($expenses->filter(
                fn (Expense $expense): bool => CarbonImmutable::parse((string) $expense->expense_date)->lessThanOrEqualTo($cutoff),
            ));
        }

        return new CreditCardBill(
            $cycle,
            (string) $total->toScale(2),
            (string) $totalSoFar->toScale(2),
            $expenses,
        );
    }

    /** @param Collection<int, Expense> $expenses */
    private function sumExpenses(Collection $expenses): BigDecimal
    {
        $total = BigDecimal::of('0.00');

        foreach ($expenses as $expense) {
            $total = $total->plus((string) $expense->amount);
        }

        return $total;
    }
}
