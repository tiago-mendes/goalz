<?php

namespace Tests\Feature;

use App\Actions\CalculateCreditCardBill;
use App\BillingCycle;
use App\BillingCycleResolver;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreditCardBillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bill_uses_selected_card_purchase_dates_across_calendar_months_and_exact_arithmetic(): void
    {
        $card = CreditCard::factory()->create(['cycle_start_day' => 13, 'due_day' => 20]);
        $otherCard = CreditCard::factory()->for($card->user)->create();
        $account = Account::factory()->for($card->user)->create();
        $includedStart = Expense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id, 'expense_date' => '2026-09-13', 'amount' => '0.10',
        ]);
        $includedLarge = Expense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id, 'expense_date' => '2026-09-20', 'amount' => '999999999999.99',
        ]);
        $includedEnd = Expense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id, 'expense_date' => '2026-10-12', 'amount' => '0.20',
        ]);
        Expense::factory()->for($card->user)->create(['credit_card_id' => $card->id, 'expense_date' => '2026-09-12']);
        Expense::factory()->for($card->user)->create(['credit_card_id' => $card->id, 'expense_date' => '2026-10-13']);
        Expense::factory()->for($card->user)->create(['credit_card_id' => $otherCard->id, 'expense_date' => '2026-09-20']);
        Expense::factory()->for($card->user)->create(['payment_account_id' => $account->id, 'expense_date' => '2026-09-20']);
        Expense::factory()->for($card->user)->create(['expense_date' => '2026-09-20']);
        Expense::factory()->for($card->user)->deleted()->create(['credit_card_id' => $card->id, 'expense_date' => '2026-09-20']);
        Expense::factory()->create(['credit_card_id' => $card->id, 'expense_date' => '2026-09-20']);
        $cycle = app(BillingCycleResolver::class)->dueIn(CarbonImmutable::parse('2026-10-01'), 13, 20);

        $bill = app(CalculateCreditCardBill::class)->handle($card->user, $card, $cycle);

        $this->assertSame('2026-09-13', $bill->cycle->start->toDateString());
        $this->assertSame('2026-10-12', $bill->cycle->end->toDateString());
        $this->assertSame('1000000000000.29', $bill->total);
        $this->assertTrue($bill->expenses->contains($includedStart));
        $this->assertTrue($bill->expenses->contains($includedLarge));
        $this->assertTrue($bill->expenses->contains($includedEnd));
        $this->assertCount(3, $bill->expenses);
        $this->assertSame('100.00', $account->refresh()->current_balance);
    }

    public function test_bill_totals_split_expenses_by_today_with_inclusive_effective_boundaries(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $card = CreditCard::factory()->create(['cycle_start_day' => 10, 'due_day' => 20]);
        $datesAndAmounts = [
            '2026-09-09' => '1.00',
            '2026-09-10' => '54.50',
            '2026-09-20' => '164.80',
            '2026-09-21' => '396.77',
            '2026-10-09' => '400.00',
            '2026-10-10' => '2.00',
        ];
        foreach ($datesAndAmounts as $date => $amount) {
            Expense::factory()->for($card->user)->create([
                'credit_card_id' => $card->id,
                'expense_date' => $date,
                'amount' => $amount,
            ]);
        }
        $cycle = app(BillingCycleResolver::class)->dueIn(CarbonImmutable::parse('2026-10-01'), 10, 20);

        $bill = app(CalculateCreditCardBill::class)->handle($card->user, $card, $cycle);

        $this->assertSame('1016.07', $bill->total);
        $this->assertSame('219.30', $bill->totalSoFar);
        $this->assertCount(4, $bill->expenses);
    }

    public function test_future_bill_has_no_total_so_far_and_completed_bill_has_the_full_expected_total(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $futureCard = CreditCard::factory()->create(['cycle_start_day' => 10, 'due_day' => 20]);
        Expense::factory()->for($futureCard->user)->create([
            'credit_card_id' => $futureCard->id,
            'expense_date' => '2026-10-10',
            'amount' => '75.25',
        ]);
        $futureCycle = app(BillingCycleResolver::class)->dueIn(CarbonImmutable::parse('2026-11-01'), 10, 20);

        $futureBill = app(CalculateCreditCardBill::class)->handle($futureCard->user, $futureCard, $futureCycle);

        $this->assertSame('75.25', $futureBill->total);
        $this->assertSame('0.00', $futureBill->totalSoFar);

        $closedCard = CreditCard::factory()->create(['cycle_start_day' => 10, 'due_day' => 20]);
        Expense::factory()->for($closedCard->user)->create([
            'credit_card_id' => $closedCard->id,
            'expense_date' => '2026-08-11',
            'amount' => '164.80',
        ]);
        $closedCycle = new BillingCycle(
            CarbonImmutable::parse('2026-08-11'),
            CarbonImmutable::parse('2026-09-12'),
            CarbonImmutable::parse('2026-09-20'),
        );

        $closedBill = app(CalculateCreditCardBill::class)->handle($closedCard->user, $closedCard, $closedCycle);

        $this->assertSame('164.80', $closedBill->total);
        $this->assertSame('164.80', $closedBill->totalSoFar);
    }

    public function test_deleted_expense_is_excluded_from_both_totals_until_restored(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $card = CreditCard::factory()->create(['cycle_start_day' => 10, 'due_day' => 20]);
        $expense = Expense::factory()->for($card->user)->deleted()->create([
            'credit_card_id' => $card->id,
            'expense_date' => '2026-09-20',
            'amount' => '54.50',
        ]);
        $cycle = app(BillingCycleResolver::class)->dueIn(CarbonImmutable::parse('2026-10-01'), 10, 20);

        $deletedBill = app(CalculateCreditCardBill::class)->handle($card->user, $card, $cycle);

        $this->assertSame('0.00', $deletedBill->total);
        $this->assertSame('0.00', $deletedBill->totalSoFar);

        $expense->deleted_by_user_at = null;
        $expense->save();
        $restoredBill = app(CalculateCreditCardBill::class)->handle($card->user, $card, $cycle);

        $this->assertSame('54.50', $restoredBill->total);
        $this->assertSame('54.50', $restoredBill->totalSoFar);
    }

    public function test_editing_payment_source_immediately_changes_bill_membership_without_creating_an_adjustment(): void
    {
        $card = CreditCard::factory()->create(['cycle_start_day' => 13, 'due_day' => 20]);
        $account = Account::factory()->for($card->user)->create();
        $expense = Expense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id,
            'expense_date' => '2026-09-20',
            'amount' => '500.00',
        ]);
        $cycle = app(BillingCycleResolver::class)->dueIn(CarbonImmutable::parse('2026-10-01'), 13, 20);
        $this->actingAs($card->user);
        $this->assertSame('500.00', app(CalculateCreditCardBill::class)->handle($card->user, $card, $cycle)->total);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])
            ->set('payment_source', 'account:'.$account->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0.00', app(CalculateCreditCardBill::class)->handle($card->user, $card, $cycle)->total);
        $this->assertSame(1, $card->user->expenses()->count());
        $this->assertSame('100.00', $account->refresh()->current_balance);
    }

    public function test_current_bill_page_materializes_every_intersecting_month_and_includes_future_recurring_expenses(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $card = CreditCard::factory()->create(['cycle_start_day' => 13, 'due_day' => 20]);
        $september = FixedExpense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id, 'day_of_month' => 25, 'start_date' => '2026-09-01', 'amount' => '10.00',
        ]);
        $october = FixedExpense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id, 'day_of_month' => 2, 'start_date' => '2026-10-01', 'amount' => '25.00',
        ]);
        $this->actingAs($card->user);

        Livewire::test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->assertSeeText('Current / Projected Bill')
            ->assertSeeText('Total so far')
            ->assertSeeText('Expected total')
            ->assertSeeText('R$ 0.00')
            ->assertSeeText('R$ 35.00')
            ->assertSeeText('2026-09-25')
            ->assertSeeText('2026-10-02')
            ->call('openBill')
            ->assertHasNoErrors();

        $this->assertSame(2, $september->expenses()->count());
        $this->assertSame(1, $october->expenses()->count());
        $this->assertSame(3, $card->user->expenses()->count());
    }

    public function test_projected_bill_keeps_deleted_occurrence_deleted_and_reuses_short_month_occurrence(): void
    {
        $this->travelTo('2026-03-01 12:00:00');
        $card = CreditCard::factory()->create(['cycle_start_day' => 31, 'due_day' => 5]);
        $fixedExpense = FixedExpense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id,
            'day_of_month' => 31,
            'start_date' => '2026-02-01',
            'amount' => '40.00',
        ]);
        $this->actingAs($card->user);

        $page = Livewire::test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->assertSeeText('2026-02-28')
            ->assertSeeText('R$ 40.00');
        $expense = $fixedExpense->expenses()->where('occurrence_month', 2)->sole();
        $expense->deleted_by_user_at = now();
        $expense->save();

        $page->call('openBill')->assertHasNoErrors()->assertSeeText('R$ 0.00');

        $this->assertSame(1, $fixedExpense->expenses()->where('occurrence_month', 2)->count());
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
    }

    public function test_historical_bill_selection_uses_due_month_and_remains_available_for_inactive_card(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $card = CreditCard::factory()->inactive()->create(['cycle_start_day' => 13, 'due_day' => 20]);
        Expense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id,
            'expense_date' => '2026-09-13',
            'amount' => '75.25',
        ]);
        $this->actingAs($card->user);

        Livewire::test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->set('billMonth', '2026-10')
            ->call('openBill')
            ->assertHasNoErrors()
            ->assertSeeText('Calculated Bill')
            ->assertSeeText('2026-09-13 – 2026-10-12')
            ->assertSeeText('2026-10-20')
            ->assertSeeText('R$ 75.25')
            ->assertSeeText('Inactive');
    }

    public function test_itau_bill_due_month_keeps_index_detail_rows_and_total_on_the_same_configured_cycle(): void
    {
        $this->travelTo('2026-09-15 12:00:00');
        $card = CreditCard::factory()->create([
            'name' => 'Itaú Multi Black',
            'cycle_start_day' => 10,
            'due_day' => 20,
        ]);
        $datesAndAmounts = [
            '2026-08-10' => '1.00',
            '2026-08-11' => '2.00',
            '2026-09-09' => '3.00',
            '2026-09-10' => '4.00',
            '2026-09-11' => '5.00',
            '2026-09-12' => '6.00',
            '2026-09-13' => '7.00',
        ];
        $expenses = collect($datesAndAmounts)->map(function (string $amount, string $date) use ($card): Expense {
            return Expense::factory()->for($card->user)->create([
                'name' => 'Expense '.$date,
                'credit_card_id' => $card->id,
                'expense_date' => $date,
                'amount' => $amount,
            ]);
        });
        $this->actingAs($card->user);

        $this->get(route('credit-cards.show', ['creditCardId' => $card->id, 'month' => '2026-10']))
            ->assertSeeText('2026-09-10 – 2026-10-09')
            ->assertSeeText('2026-10-20')
            ->assertDontSeeText('2026-09-20');

        Livewire::test('pages::credit-cards.index')
            ->assertSeeText('R$ 22.00')
            ->assertSeeText('2026-10-20')
            ->assertSee(route('credit-cards.show', ['creditCardId' => $card->id, 'month' => '2026-10']), escape: false);

        $page = Livewire::withQueryParams(['month' => '2026-10'])
            ->test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->assertSet('billMonth', '2026-10')
            ->assertSet('selectedBillMonth', '2026-10')
            ->assertSeeText('2026-09-10 – 2026-10-09')
            ->assertSeeText('2026-10-20')
            ->assertSeeText('R$ 22.00')
            ->assertSeeText(['Expense 2026-09-10', 'Expense 2026-09-11', 'Expense 2026-09-12', 'Expense 2026-09-13'])
            ->assertDontSeeText(['Expense 2026-08-10', 'Expense 2026-08-11', 'Expense 2026-09-09', '2026-09-20']);

        $this->assertSame(
            $expenses->slice(3)->pluck('id')->values()->all(),
            $page->get('bill')->expenses->pluck('id')->all(),
        );
    }

    public function test_bill_expenses_render_safe_category_icons_and_unavailable_fallbacks(): void
    {
        $card = CreditCard::factory()->inactive()->create(['cycle_start_day' => 13, 'due_day' => 20]);
        $category = ExpenseCategory::factory()->for($card->user)->create([
            'name' => 'Historical category',
            'icon' => 'gift',
            'color' => '#ABCDEF',
            'is_active' => false,
        ]);
        $foreignCategory = ExpenseCategory::factory()->create(['name' => 'Private category']);
        Expense::factory()->for($card->user)->create([
            'name' => 'Historical categorized expense',
            'credit_card_id' => $card->id,
            'expense_category_id' => $category->id,
            'expense_date' => '2026-09-13',
        ]);
        Expense::factory()->for($card->user)->create([
            'name' => 'Unavailable categorized expense',
            'credit_card_id' => $card->id,
            'expense_category_id' => $foreignCategory->id,
            'expense_date' => '2026-09-14',
        ]);
        $this->actingAs($card->user);

        Livewire::test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->set('billMonth', '2026-10')
            ->call('openBill')
            ->assertSeeText(['Historical category', 'Unavailable categorized expense', 'Category unavailable'])
            ->assertSee('style="color: #ABCDEF"', escape: false)
            ->assertSee('aria-hidden="true"', escape: false)
            ->assertDontSee('not-an-icon');
    }
}
