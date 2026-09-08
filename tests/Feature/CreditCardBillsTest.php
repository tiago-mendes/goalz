<?php

namespace Tests\Feature;

use App\Actions\CalculateCreditCardBill;
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
            ->assertSeeText('BRL 35.00')
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
            ->assertSeeText('BRL 40.00');
        $expense = $fixedExpense->expenses()->where('occurrence_month', 2)->sole();
        $expense->deleted_by_user_at = now();
        $expense->save();

        $page->call('openBill')->assertHasNoErrors()->assertSeeText('BRL 0.00');

        $this->assertSame(1, $fixedExpense->expenses()->where('occurrence_month', 2)->count());
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
    }

    public function test_historical_bill_selection_uses_due_month_and_remains_available_for_inactive_card(): void
    {
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
            ->assertSeeText('BRL 75.25')
            ->assertSeeText('Inactive');
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
