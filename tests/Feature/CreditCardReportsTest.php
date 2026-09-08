<?php

namespace Tests\Feature;

use App\AccountType;
use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreditCardReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_spending_includes_only_owned_non_deleted_credit_card_expenses_with_exact_totals(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $other = User::factory()->create();
        $card = CreditCard::factory()->for($user)->create(['name' => 'Nubank']);
        $inactiveCard = CreditCard::factory()->for($user)->inactive()->create(['name' => 'Old Visa']);
        $foreignCard = CreditCard::factory()->for($other)->create(['name' => 'Private Card']);
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Travel']);
        $fixedExpense = FixedExpense::factory()->for($user)->create(['credit_card_id' => $card->id]);
        Expense::factory()->for($user)->create(['credit_card_id' => $card->id, 'expense_category_id' => $category->id, 'expense_date' => '2026-01-01', 'amount' => '10.10']);
        Expense::factory()->for($user)->create(['credit_card_id' => $inactiveCard->id, 'expense_date' => '2026-02-28', 'amount' => '20.20']);
        Expense::factory()->for($user)->create(['credit_card_id' => $card->id, 'fixed_expense_id' => $fixedExpense->id, 'occurrence_year' => 2026, 'occurrence_month' => 2, 'expense_date' => '2026-02-15', 'amount' => '30.30']);
        Expense::factory()->for($user)->deleted()->create(['credit_card_id' => $card->id, 'expense_date' => '2026-02-15', 'amount' => '999.99']);
        Expense::factory()->for($user)->create(['payment_account_id' => Account::factory()->for($user)->create(['type' => AccountType::Checking])->id, 'expense_date' => '2026-02-15', 'amount' => '500.00']);
        Expense::factory()->for($user)->create(['expense_date' => '2026-02-15', 'amount' => '600.00']);
        Expense::factory()->for($user)->create(['credit_card_id' => $card->id, 'expense_date' => '2025-12-31', 'amount' => '700.00']);
        Expense::factory()->for($other)->create(['credit_card_id' => $foreignCard->id, 'expense_date' => '2026-02-15', 'amount' => '800.00']);
        $this->actingAs($user);

        $report = Livewire::withQueryParams(['from' => '2026-01', 'to' => '2026-03'])
            ->test('pages::reports.credit-cards')->get('report');

        $this->assertSame('60.60', $report['total']);
        $this->assertSame(3, $report['expenseCount']);
        $this->assertSame('20.20', $report['averageMonthly']);
        $this->assertSame(['10.10', '50.50', '0.00'], array_column($report['months'], 'amount'));
        $this->assertSame(['Nubank', 'Old Visa'], array_column($report['cards'], 'name'));
        $this->assertSame(['66.7', '33.3'], array_column($report['cards'], 'percentage'));
    }

    public function test_restored_expense_reappears_and_payment_source_changes_remove_it_from_reports(): void
    {
        $user = User::factory()->create();
        $card = CreditCard::factory()->for($user)->create();
        $account = Account::factory()->for($user)->create();
        $expense = Expense::factory()->for($user)->deleted()->create(['credit_card_id' => $card->id, 'expense_date' => '2026-01-15', 'amount' => '12.34']);
        $this->actingAs($user);

        $page = Livewire::withQueryParams(['from' => '2026-01', 'to' => '2026-01'])->test('pages::reports.credit-cards');
        $this->assertSame('0.00', $page->get('report')['total']);

        $expense->deleted_by_user_at = null;
        $expense->save();
        $page = Livewire::withQueryParams(['from' => '2026-01', 'to' => '2026-01'])->test('pages::reports.credit-cards');
        $this->assertSame('12.34', $page->get('report')['total']);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])
            ->set('payment_source', 'account:'.$account->id)->call('save')->assertHasNoErrors();
        $page = Livewire::test('pages::reports.credit-cards');
        $this->assertSame('0.00', $page->get('report')['total']);
    }

    public function test_category_spending_keeps_inactive_categories_and_uses_safe_unavailable_fallback(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $card = CreditCard::factory()->for($user)->create();
        $inactive = ExpenseCategory::factory()->for($user)->inactive()->create(['name' => 'Old Travel']);
        $foreign = ExpenseCategory::factory()->for($other)->create(['name' => 'Private Category']);
        Expense::factory()->for($user)->create(['credit_card_id' => $card->id, 'expense_category_id' => $inactive->id, 'expense_date' => '2026-01-10', 'amount' => '25.00']);
        Expense::factory()->for($user)->create(['credit_card_id' => $card->id, 'expense_category_id' => $foreign->id, 'expense_date' => '2026-01-11', 'amount' => '5.00']);
        $this->actingAs($user);

        $report = Livewire::withQueryParams(['from' => '2026-01', 'to' => '2026-01'])->test('pages::reports.credit-cards')->get('report');

        $this->assertSame(['Old Travel', 'Category unavailable'], array_column($report['categories'], 'name'));
        $this->assertSame(['25.00', '5.00'], array_column($report['categories'], 'amount'));
        $this->assertSame(['83.3', '16.7'], array_column($report['categories'], 'percentage'));
        $this->assertNotSame('Private Category', $report['categories'][1]['name']);
    }

    public function test_calculated_bills_use_due_months_and_current_cycles_without_materializing_future_expenses(): void
    {
        $user = User::factory()->create();
        $first = CreditCard::factory()->for($user)->create(['name' => 'Nubank', 'cycle_start_day' => 13, 'due_day' => 20]);
        $second = CreditCard::factory()->for($user)->create(['name' => 'Itaú Visa', 'cycle_start_day' => 1, 'due_day' => 10]);
        Expense::factory()->for($user)->create(['credit_card_id' => $first->id, 'expense_date' => '2026-09-25', 'amount' => '100.01']);
        Expense::factory()->for($user)->create(['credit_card_id' => $first->id, 'expense_date' => '2026-10-12', 'amount' => '0.02']);
        Expense::factory()->for($user)->create(['credit_card_id' => $second->id, 'expense_date' => '2026-09-30', 'amount' => '50.00']);
        FixedExpense::factory()->for($user)->create(['credit_card_id' => $first->id, 'start_date' => '2026-10-01', 'day_of_month' => 15, 'amount' => '999.00']);
        $this->actingAs($user);

        $page = Livewire::withQueryParams(['from' => '2026-10', 'to' => '2026-11'])->test('pages::reports.credit-cards');
        $report = $page->get('report');

        $this->assertSame('150.03', $report['billMonths'][0]['amount']);
        $this->assertSame('0.00', $report['billMonths'][1]['amount']);
        $this->assertSame(['Itaú Visa', 'Nubank'], array_column($report['bills'], 'card'));
        $this->assertSame(['2026-09-01 – 2026-09-30', '2026-09-13 – 2026-10-12'], array_column($report['bills'], 'cycle'));
        $this->assertSame(3, $user->expenses()->count());
        $this->assertSame(1, $user->fixedExpenses()->count());
    }

    public function test_calculated_bill_card_selector_preserves_owned_inactive_cards_and_rejects_foreign_cards(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $inactive = CreditCard::factory()->for($user)->inactive()->create(['name' => 'Old Card']);
        $foreign = CreditCard::factory()->for($other)->create(['name' => 'Private Card']);
        Expense::factory()->for($user)->create(['credit_card_id' => $inactive->id, 'expense_date' => '2026-09-20', 'amount' => '75.25']);
        $this->actingAs($user);

        $page = Livewire::withQueryParams(['from' => '2026-10', 'to' => '2026-10', 'billCardId' => $inactive->id])->test('pages::reports.credit-cards');
        $page->assertSet('billCardId', $inactive->id);
        $this->assertSame(['Old Card'], array_column($page->get('report')['bills'], 'card'));

        $page->set('billCardId', $foreign->id)->assertHasErrors('billCardId')->assertSet('billCardId', null);
        $page->set('billCardId', 999999)->assertHasErrors('billCardId')->assertSet('billCardId', null);
    }

    public function test_reports_default_to_six_months_preserve_period_and_have_safe_empty_states(): void
    {
        $this->travelTo('2026-09-07');
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::reports.credit-cards')
            ->assertSet('from', '2026-04')->assertSet('to', '2026-09')->assertSet('billCardId', null)
            ->assertSeeText('No credit card spending is available for this period.')
            ->assertSeeText('No calculated bills are available for this period.');

        Livewire::withQueryParams(['from' => '2025-01', 'to' => '2025-02'])
            ->test('pages::reports.credit-cards')
            ->assertSet('from', '2025-01')->assertSet('to', '2025-02');
    }

    public function test_reports_exclude_foreign_cards_and_admin_has_no_financial_bypass(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create();
        $foreign = CreditCard::factory()->for($other)->create(['name' => 'Private Card']);
        Expense::factory()->for($other)->create(['credit_card_id' => $foreign->id, 'expense_date' => '2026-01-10', 'amount' => '999.00']);
        $this->actingAs($admin);

        Livewire::test('pages::reports.credit-cards')
            ->assertSet('billCardId', null)
            ->assertDontSeeText('Private Card')
            ->assertDontSeeText('999.00');
    }

    public function test_opening_and_updating_reports_is_read_only_including_fixed_expense_materialization(): void
    {
        $user = User::factory()->create();
        $card = CreditCard::factory()->for($user)->create();
        $fixedExpense = FixedExpense::factory()->for($user)->create(['credit_card_id' => $card->id]);
        $account = Account::factory()->for($user)->create();
        $snapshot = AccountBalanceSnapshot::factory()->for($account)->create();
        $this->actingAs($user);

        Livewire::test('pages::reports.credit-cards')
            ->set('from', '2026-01')->set('to', '2026-12')->call('applyPeriod');

        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(1, FixedExpense::query()->count());
        $this->assertSame(1, CreditCard::query()->count());
        $this->assertSame(1, AccountBalanceSnapshot::query()->count());
        $this->assertModelExists($fixedExpense);
        $this->assertModelExists($snapshot);
    }

    public function test_chart_payload_converts_exact_report_values_only_at_the_presentation_boundary(): void
    {
        $user = User::factory()->create();
        $card = CreditCard::factory()->for($user)->create();
        Expense::factory()->for($user)->create(['credit_card_id' => $card->id, 'expense_date' => '2026-01-10', 'amount' => '12.34']);
        $this->actingAs($user);

        $chartData = Livewire::withQueryParams(['from' => '2026-01', 'to' => '2026-01'])->test('pages::reports.credit-cards')->get('chartData');

        $this->assertSame([12.34], $chartData['monthly']['amounts']);
        $this->assertSame([12.34], $chartData['cards']['amounts']);
        $this->assertSame([12.34], $chartData['categories']['amounts']);
        $this->assertSame([12.34], $chartData['bills']['amounts']);
    }
}
