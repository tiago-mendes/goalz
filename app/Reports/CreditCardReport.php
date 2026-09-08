<?php

namespace App\Reports;

use App\BillingCycle;
use App\BillingCycleResolver;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class CreditCardReport
{
    public function __construct(private readonly BillingCycleResolver $billingCycleResolver) {}

    /**
     * @return array{
     *     months: list<array{key: string, label: string, amount: string}>,
     *     cards: list<array{key: int, name: string, amount: string, percentage: string}>,
     *     categories: list<array{key: string, name: string, amount: string, percentage: string, icon: string, color: string}>,
     *     bills: list<array{key: string, dueMonth: string, card: string, cycle: string, dueDate: string, amount: string}>,
     *     billMonths: list<array{key: string, label: string, amount: string}>,
     *     total: string,
     *     expenseCount: int,
     *     averageMonthly: string,
     *     cardsAvailable: Collection<int, CreditCard>,
     *     selectedCard: CreditCard|null,
     * }
     */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $cardId = null): array
    {
        $cardsAvailable = $this->cards($user);
        $selectedCard = $cardId === null ? null : $cardsAvailable->firstWhere('id', $cardId);
        $purchaseExpenses = $this->expensesBetween($user, $from->startOfMonth(), $to->endOfMonth());
        $months = $this->purchaseMonths($from, $to);
        $total = BigDecimal::zero();
        $cardTotals = [];
        $categoryTotals = [];

        foreach ($purchaseExpenses as $expense) {
            $amount = BigDecimal::of($expense->amount);
            $monthKey = $expense->expense_date->format('Y-m');
            $months[$monthKey]['amount'] = $months[$monthKey]['amount']->plus($amount);
            $total = $total->plus($amount);
            $cardTotals[$expense->credit_card_id] = ($cardTotals[$expense->credit_card_id] ?? BigDecimal::zero())->plus($amount);
            $categoryKey = $expense->expense_category_id === null ? 'unavailable' : (string) $expense->expense_category_id;
            $categoryTotals[$categoryKey] = ($categoryTotals[$categoryKey] ?? BigDecimal::zero())->plus($amount);
        }

        $cards = $this->cardTotals($cardTotals, $cardsAvailable, $total);
        $categories = $this->categoryTotals($categoryTotals, $user, $total);
        $billMonths = $this->purchaseMonths($from, $to);
        [$bills, $billMonths] = $this->calculatedBills($user, $cardsAvailable, $selectedCard, $from, $to, $billMonths);
        $monthCount = count($months);

        return [
            'months' => array_map(fn (array $month): array => [
                ...$month,
                'amount' => (string) $month['amount']->toScale(2),
            ], array_values($months)),
            'cards' => $cards,
            'categories' => $categories,
            'bills' => $bills,
            'billMonths' => array_map(fn (array $month): array => [
                ...$month,
                'amount' => (string) $month['amount']->toScale(2),
            ], array_values($billMonths)),
            'total' => (string) $total->toScale(2),
            'expenseCount' => $purchaseExpenses->count(),
            'averageMonthly' => (string) $total->dividedBy($monthCount, 2, RoundingMode::HalfUp)->toScale(2),
            'cardsAvailable' => $cardsAvailable,
            'selectedCard' => $selectedCard,
        ];
    }

    /** @return Collection<int, CreditCard> */
    public function cards(User $user): Collection
    {
        return $user->creditCards()->orderBy('name')->orderBy('id')->get();
    }

    /** @return array<string, array{key: string, label: string, amount: BigDecimal}> */
    private function purchaseMonths(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $month = $from->startOfMonth();

        while ($month->lessThanOrEqualTo($to->startOfMonth())) {
            $key = $month->format('Y-m');
            $months[$key] = ['key' => $key, 'label' => $month->format('F Y'), 'amount' => BigDecimal::zero()];
            $month = $month->addMonth();
        }

        return $months;
    }

    /** @return Collection<int, Expense> */
    private function expensesBetween(User $user, CarbonImmutable $from, CarbonImmutable $to, ?Collection $cards = null): Collection
    {
        $query = $user->expenses()
            ->whereNull('deleted_by_user_at')
            ->whereNotNull('credit_card_id')
            ->whereHas('creditCard', fn ($query) => $query->whereBelongsTo($user))
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->with([
                'creditCard' => fn ($query) => $query->whereBelongsTo($user),
                'expenseCategory' => fn ($query) => $query->whereBelongsTo($user),
            ])
            ->orderBy('expense_date')
            ->orderBy('id');

        if ($cards !== null) {
            $query->whereIn('credit_card_id', $cards->modelKeys());
        }

        return $query->get([
            'id', 'expense_category_id', 'credit_card_id', 'amount', 'expense_date', 'fixed_expense_id',
        ]);
    }

    /** @param array<int, BigDecimal> $cardTotals @return list<array{key: int, name: string, amount: string, percentage: string}> */
    private function cardTotals(array $cardTotals, Collection $cards, BigDecimal $total): array
    {
        $result = [];

        foreach ($cardTotals as $cardId => $amount) {
            $card = $cards->firstWhere('id', $cardId);
            if ($card === null) {
                continue;
            }
            $result[] = [
                'key' => $card->id,
                'name' => $card->name,
                'amount' => (string) $amount->toScale(2),
                'percentage' => $this->percentage($amount, $total),
            ];
        }

        usort($result, fn (array $left, array $right): int => BigDecimal::of($right['amount'])->compareTo(BigDecimal::of($left['amount'])) ?: strcasecmp($left['name'], $right['name']));

        return $result;
    }

    /** @param array<string, BigDecimal> $categoryTotals @return list<array{key: string, name: string, amount: string, percentage: string, icon: string, color: string}> */
    private function categoryTotals(array $categoryTotals, User $user, BigDecimal $total): array
    {
        $categories = $user->expenseCategories()->get()->keyBy('id');
        $result = [];

        foreach ($categoryTotals as $categoryId => $amount) {
            $category = $categoryId === 'unavailable' ? null : $categories->get((int) $categoryId);
            $result[] = [
                'key' => $categoryId,
                'name' => $category?->name ?? 'Category unavailable',
                'amount' => (string) $amount->toScale(2),
                'percentage' => $this->percentage($amount, $total),
                'icon' => $category?->safeIcon() ?? ExpenseCategory::DEFAULT_ICON,
                'color' => $category?->safeColor() ?? ExpenseCategory::DEFAULT_COLOR,
            ];
        }

        usort($result, fn (array $left, array $right): int => BigDecimal::of($right['amount'])->compareTo(BigDecimal::of($left['amount'])) ?: strcasecmp($left['name'], $right['name']));

        return $result;
    }

    /** @return array{0: list<array{key: string, dueMonth: string, card: string, cycle: string, dueDate: string, amount: string}>, 1: array<string, array{key: string, label: string, amount: BigDecimal}>} */
    private function calculatedBills(User $user, Collection $cards, ?CreditCard $selectedCard, CarbonImmutable $from, CarbonImmutable $to, array $billMonths): array
    {
        $billCards = $selectedCard === null ? $cards : new Collection([$selectedCard]);
        $cycles = [];
        $earliest = null;
        $latest = null;

        foreach ($billCards as $card) {
            $month = $from->startOfMonth();
            while ($month->lessThanOrEqualTo($to->startOfMonth())) {
                $cycle = $this->billingCycleResolver->dueIn($month, $card->cycle_start_day, $card->due_day);
                $cycles[] = ['card' => $card, 'cycle' => $cycle, 'dueMonth' => $month->format('Y-m')];
                $earliest = $earliest === null || $cycle->start->lessThan($earliest) ? $cycle->start : $earliest;
                $latest = $latest === null || $cycle->end->greaterThan($latest) ? $cycle->end : $latest;
                $month = $month->addMonth();
            }
        }

        if ($cycles === [] || $earliest === null || $latest === null) {
            return [[], $billMonths];
        }

        $expenses = $this->expensesBetween($user, $earliest, $latest, $billCards)->groupBy('credit_card_id');
        $bills = [];

        foreach ($cycles as $cycleData) {
            /** @var CreditCard $card */
            $card = $cycleData['card'];
            /** @var BillingCycle $cycle */
            $cycle = $cycleData['cycle'];
            $total = BigDecimal::zero();

            foreach ($expenses->get($card->id, new Collection) as $expense) {
                if (! $expense->expense_date->lessThan($cycle->start) && ! $expense->expense_date->greaterThan($cycle->end)) {
                    $total = $total->plus($expense->amount);
                }
            }

            $billMonths[$cycleData['dueMonth']]['amount'] = $billMonths[$cycleData['dueMonth']]['amount']->plus($total);
            if ($total->isZero()) {
                continue;
            }

            $bills[] = [
                'key' => $card->id.'-'.$cycleData['dueMonth'],
                'dueMonth' => $cycleData['dueMonth'],
                'card' => $card->name,
                'cycle' => $cycle->start->toDateString().' – '.$cycle->end->toDateString(),
                'dueDate' => $cycle->dueDate->toDateString(),
                'amount' => (string) $total->toScale(2),
            ];
        }

        usort($bills, fn (array $left, array $right): int => strcmp($left['dueMonth'], $right['dueMonth']) ?: strcasecmp($left['card'], $right['card']));

        return [$bills, $billMonths];
    }

    private function percentage(BigDecimal $amount, BigDecimal $total): string
    {
        if ($total->isZero()) {
            return '0.0';
        }

        return (string) $amount->multipliedBy(100)->dividedBy($total, 1, RoundingMode::HalfUp);
    }
}
