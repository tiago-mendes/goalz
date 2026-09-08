<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\MonthlyIncome;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
        Livewire::test('pages::dashboard')->assertForbidden();
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()->assertSeeText('Monthly Income')->assertSeeText('Expenses')->assertSeeText('Remaining')
            ->assertDontSee('Planned Fixed Expenses')->assertDontSee('Actual Expenses');
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_overview_is_private_and_uses_the_owners_currency(string $role): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $user = User::factory()->create(['role' => $role, 'currency' => 'USD']);
        $other = User::factory()->create();
        MonthlyIncome::factory()->for($user)->create(['year' => 2026, 'month' => 9, 'amount' => '1000.50']);
        $foreign = MonthlyIncome::factory()->for($other)->create(['year' => 2026, 'month' => 9, 'amount' => '9876.54']);
        FixedExpense::factory()->for($user)->create(['name' => 'Own rent', 'amount' => '100.25']);
        FixedExpense::factory()->for($other)->create(['name' => 'Private rent', 'amount' => '7654.32']);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertSeeText('Own rent')->assertSeeText('USD 1000.50')
            ->assertSeeText('USD 100.25')->assertSeeText('USD 900.25')
            ->assertDontSee('Private rent')->assertDontSee('9876.54')->assertDontSee('7654.32');
        Livewire::test('pages::dashboard')->call('openMonth', ['user_id' => $other->id])
            ->assertSee('Own rent')->assertDontSee('Private rent')->assertDontSee('9876.54');

        $this->assertSame('9876.54', $foreign->refresh()->amount);
        $this->assertDatabaseCount('monthly_incomes', 2);
        $this->assertDatabaseCount('fixed_expenses', 2);
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_month_changes_refresh_all_figures_and_preserve_snapshots(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $user = User::factory()->create(['default_monthly_income' => '1000.00']);
        $snapshot = MonthlyIncome::factory()->for($user)->create(['year' => 2026, 'month' => 9, 'amount' => '500.00']);
        $original = $snapshot->refresh()->getRawOriginal();
        FixedExpense::factory()->for($user)->create(['name' => 'October cost', 'amount' => '10.25', 'start_date' => '2026-10-01']);
        $this->actingAs($user);

        $page = Livewire::test('pages::dashboard')->assertSet('selectedMonth', '2026-09')
            ->assertSee('September 2026')->assertSet('actualTotal', '0.00')->assertSet('remaining', '500.00')
            ->assertDontSee('October cost');
        $page->set('month', '2026-10')->call('openMonth')->assertHasNoErrors()
            ->assertSee('October 2026')->assertSee('October cost')->assertSee('1000.00')
            ->assertSet('actualTotal', '10.25')->assertSet('remaining', '989.75');
        Livewire::test('pages::monthly-income.index')->set('default_monthly_income', '2000.00')->call('saveDefault');
        $page->set('month', '2026-09')->call('openMonth')->assertSet('remaining', '500.00');
        $page->set('month', '2026-10')->call('openMonth')->assertSet('remaining', '989.75');
        $page->set('month', '2026-11')->call('openMonth')->assertSet('remaining', '1989.75');

        $this->assertSame($original, $snapshot->refresh()->getRawOriginal());
        $this->assertDatabaseCount('monthly_incomes', 3);
    }

    public function test_unconfigured_income_has_no_remaining_and_creates_nothing(): void
    {
        $user = User::factory()->create(['default_monthly_income' => null]);
        FixedExpense::factory()->for($user)->create(['amount' => '0.30', 'start_date' => '1000-01-01']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->assertSee('Not configured')->assertSee('Not available')
            ->assertSet('remaining', null)->assertSet('actualTotal', '0.30');

        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    public function test_zero_default_is_valid_and_negative_remaining_is_exact(): void
    {
        $user = User::factory()->create(['default_monthly_income' => '0.00', 'currency' => 'BRL']);
        FixedExpense::factory()->for($user)->create(['amount' => '0.10', 'start_date' => '1000-01-01']);
        FixedExpense::factory()->for($user)->create(['amount' => '0.20', 'start_date' => '1000-01-01']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->assertSee('BRL 0.00')->assertSet('actualTotal', '0.30')
            ->assertSet('remaining', '-0.30')->assertDontSee('Not configured')->call('openMonth');

        $this->assertSame('0.00', $user->monthlyIncomes()->sole()->amount);
    }

    #[TestWith(['2026-01', '2026-01-31'])]
    #[TestWith(['2026-04', '2026-04-30'])]
    #[TestWith(['2026-02', '2026-02-28'])]
    #[TestWith(['2028-02', '2028-02-29'])]
    public function test_occurrence_clamps_to_the_last_calendar_day(string $month, string $expected): void
    {
        $user = User::factory()->create();
        FixedExpense::factory()->for($user)->create(['name' => 'Month end bill', 'day_of_month' => 31, 'start_date' => '2020-01-01']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->set('month', $month)->call('openMonth')
            ->assertSee('Month end bill')->assertSee('datetime="'.$expected.'"', false);
    }

    public function test_start_dates_and_current_active_status_control_historical_planning(): void
    {
        $user = User::factory()->create();
        FixedExpense::factory()->for($user)->create(['name' => 'Too early', 'day_of_month' => 10, 'start_date' => '2026-09-20', 'amount' => '10.00']);
        FixedExpense::factory()->for($user)->create(['name' => 'On start date', 'day_of_month' => 31, 'start_date' => '2026-09-30', 'amount' => '20.00']);
        FixedExpense::factory()->for($user)->inactive()->create(['name' => 'Inactive bill', 'start_date' => '2020-01-01']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->set('month', '2026-09')->call('openMonth')
            ->assertDontSee('Too early')->assertDontSee('Inactive bill')->assertSee('On start date')->assertSet('actualTotal', '20.00')
            ->set('month', '2026-10')->call('openMonth')->assertSee('Too early')->assertSet('actualTotal', '30.00')
            ->set('month', '2025-01')->call('openMonth')->assertDontSee('Inactive bill')->assertSet('actualTotal', '0.00');
    }

    #[TestWith([''])]
    #[TestWith(['2026-00'])]
    #[TestWith(['2026-13'])]
    #[TestWith(['invalid'])]
    #[TestWith(['0999-12'])]
    #[TestWith(['10000-01'])]
    public function test_invalid_month_does_not_change_the_open_month_or_create_snapshots(string $month): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $this->actingAs(User::factory()->create(['default_monthly_income' => '1.00']));

        Livewire::test('pages::dashboard')->set('month', $month)->call('openMonth')
            ->assertHasErrors('month')->assertSet('selectedMonth', '2026-09');

        $this->assertDatabaseCount('monthly_incomes', 1);
    }

    public function test_selected_month_cannot_be_tampered_with(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::dashboard');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('selectedMonth', '2026-13');
    }

    public function test_owner_cannot_be_supplied_in_livewire_state(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::dashboard');
        $this->expectException(PublicPropertyNotFoundException::class);
        $page->set('user_id', 123);
    }

    public function test_foreign_category_is_hidden_and_expense_names_are_escaped(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->create(['name' => 'Foreign category']);
        Expense::factory()->for($user)->create(['name' => '<script>alert(1)</script>', 'expense_category_id' => $category->id]);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->assertSee('Category unavailable')->assertDontSee('Foreign category')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_actual_total_includes_owned_manual_and_recurring_expenses_only(): void
    {
        $user = User::factory()->create(['default_monthly_income' => '1000.30']);
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        $fixed = FixedExpense::factory()->for($user)->create(['name' => 'Recurring lunch', 'amount' => '0.20', 'expense_category_id' => $category->id]);
        Expense::factory()->for($user)->create(['name' => 'Manual lunch', 'amount' => '0.10', 'expense_category_id' => $category->id]);
        Expense::factory()->for($user)->deleted()->create(['name' => 'Deleted lunch', 'amount' => '99.99', 'expense_category_id' => $category->id]);
        Expense::factory()->for($user)->create(['name' => 'Other month', 'amount' => '88.88', 'expense_date' => '2026-10-01', 'expense_category_id' => $category->id]);
        Expense::factory()->create(['name' => 'Private lunch', 'amount' => '77.77']);
        $this->actingAs($user);

        $page = Livewire::test('pages::dashboard')->set('month', '2026-09')->call('openMonth');

        $page->assertSet('actualTotal', '0.30')->assertSet('remaining', '1000.00')
            ->assertSee('Manual lunch')->assertSee('Recurring lunch')->assertSee('Food')
            ->assertSeeText(['Recurring', '0.20', '66.7%', 'Manual', '0.10', '33.3%'])
            ->assertDontSee('Deleted lunch')->assertDontSee('Other month')->assertDontSee('Private lunch');
        $this->assertSame($fixed->id, $user->expenses()->where('fixed_expense_id', $fixed->id)->sole()->fixed_expense_id);
    }

    public function test_dashboard_materialization_is_idempotent_and_does_not_regenerate_deleted_occurrence(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $fixed = FixedExpense::factory()->create(['name' => 'Recurring bill', 'amount' => '12.34', 'day_of_month' => 25]);
        $this->actingAs($fixed->user);

        $page = Livewire::test('pages::dashboard')->assertSet('actualTotal', '12.34')
            ->assertSeeText(['Recurring', '12.34', '100.0%', 'Manual', '0%']);
        $expense = $fixed->expenses()->sole();
        $expense->deleted_by_user_at = now();
        $expense->save();
        $page->call('openMonth');
        Livewire::test('pages::dashboard')->assertSet('actualTotal', '0.00');

        $this->assertSame(1, $fixed->expenses()->count());
    }

    public function test_all_selected_month_expenses_are_ordered_and_linked_to_the_selected_month(): void
    {
        $user = User::factory()->create(['currency' => 'BRL', 'default_monthly_income' => '5000.00']);
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Safe category', 'icon' => 'not-an-icon', 'color' => 'red']);
        $foreignCategory = ExpenseCategory::factory()->create(['name' => 'Private category']);
        foreach (range(1, 6) as $day) {
            Expense::factory()->for($user)->create([
                'name' => 'Expense '.$day,
                'expense_date' => '2026-09-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
                'expense_category_id' => $category->id,
            ]);
        }
        Expense::factory()->for($user)->create(['name' => 'Unavailable category', 'expense_date' => '2026-09-07', 'expense_category_id' => $foreignCategory->id]);
        Expense::factory()->for($user)->create(['name' => 'Tie breaker first', 'expense_date' => '2026-09-06', 'expense_category_id' => $category->id]);
        Expense::factory()->for($user)->deleted()->create(['name' => 'Deleted recent', 'expense_date' => '2026-09-30', 'expense_category_id' => $category->id]);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->set('month', '2026-09')->call('openMonth')
            ->assertSeeInOrder(['Unavailable category', 'Tie breaker first', 'Expense 6', 'Expense 5', 'Expense 4', 'Expense 3', 'Expense 2', 'Expense 1'])
            ->assertDontSee('Deleted recent')
            ->assertSee('Manual')->assertSee('Category unavailable')
            ->assertSee(route('expenses.index', ['month' => '2026-09']));
    }

    public function test_remaining_allows_negative_values_and_is_unavailable_without_income(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 7));
        $user = User::factory()->create(['default_monthly_income' => '5.00']);
        Expense::factory()->for($user)->create(['amount' => '6.00', 'expense_date' => '2026-10-01']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->assertSet('actualTotal', '6.00')->assertSet('remaining', '-1.00');

        $user->default_monthly_income = null;
        $user->save();
        $user->monthlyIncomes()->delete();
        Livewire::test('pages::dashboard')->assertSet('actualTotal', '6.00')->assertSet('remaining', null)->assertSee('Not available');
    }

    public function test_zero_expense_month_has_zero_breakdown_percentages(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::dashboard')->assertSet('actualTotal', '0.00')
            ->assertSeeText(['Recurring', '0.00', '0%', 'Manual']);
    }

    public function test_manual_only_expenses_have_a_full_manual_breakdown(): void
    {
        $user = User::factory()->create();
        Expense::factory()->for($user)->create(['amount' => '10.00']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->assertSeeText(['Recurring', '0.00', '0%', 'Manual', '10.00', '100.0%']);
    }

    public function test_dashboard_shows_current_financial_position_and_goal_state_without_foreign_data(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $other = User::factory()->create();
        $account = Account::factory()->for($user)->create(['current_balance' => '1000.10']);
        Account::factory()->for($user)->inactive()->create(['current_balance' => '500.00']);
        $goal = Goal::factory()->for($user)->create(['name' => 'Emergency Fund', 'target_amount' => '2000.00']);
        GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '250.05']);
        Goal::factory()->for($other)->create(['name' => 'Private Goal', 'target_amount' => '9999.99']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')
            ->assertSet('financialPosition.totalAssets', '1000.10')
            ->assertSet('financialPosition.allocatedAssets', '250.05')
            ->assertSet('financialPosition.freeAssets', '750.05')
            ->assertSeeText(['Current Financial Position', 'BRL 1000.10', 'BRL 250.05', 'BRL 750.05', 'Emergency Fund', '12.5%', 'Active'])
            ->assertDontSeeText('Private Goal');
    }

    public function test_dashboard_warns_for_overallocated_active_accounts_without_mutating_allocations(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $account = Account::factory()->for($user)->create(['current_balance' => '200.00']);
        $otherAccount = Account::factory()->for($user)->create(['current_balance' => '300.00']);
        $goal = Goal::factory()->for($user)->create(['target_amount' => '1000.00']);
        GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '260.00']);
        GoalAccountAllocation::factory()->for($goal)->for($otherAccount)->create(['amount' => '100.00']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')
            ->assertSet('financialPosition.overallocatedCount', 1)
            ->assertSet('financialPosition.overallocatedTotal', '60.00')
            ->assertSeeText(['Allocation warning', '1 account has', 'BRL 60.00', 'Review allocations']);

        $this->assertSame('260.00', $account->goalAccountAllocations()->sole()->amount);
        $this->assertSame('200.00', $account->refresh()->current_balance);
    }

    public function test_dashboard_bills_follow_selected_due_month_and_do_not_change_remaining_or_current_assets(): void
    {
        $this->travelTo('2026-09-07 12:00:00');
        $user = User::factory()->create(['currency' => 'BRL', 'default_monthly_income' => '1000.00']);
        $account = Account::factory()->for($user)->create(['current_balance' => '2000.00']);
        $card = CreditCard::factory()->for($user)->create(['name' => 'Nubank Mastercard', 'cycle_start_day' => 13, 'due_day' => 20]);
        Expense::factory()->for($user)->create(['credit_card_id' => $card->id, 'expense_date' => '2026-09-10', 'amount' => '500.00']);
        Expense::factory()->for($user)->create(['credit_card_id' => $card->id, 'expense_date' => '2026-09-13', 'amount' => '125.00']);
        $this->actingAs($user);

        $page = Livewire::test('pages::dashboard')->set('month', '2026-09')->call('openMonth')
            ->assertSet('actualTotal', '625.00')
            ->assertSet('remaining', '375.00')
            ->assertSet('financialPosition.totalAssets', '2000.00')
            ->assertSeeText(['Nubank Mastercard', 'Aug 13 – Sep 12', 'Sep 20, 2026', 'BRL 500.00']);

        $page->set('month', '2026-10')->call('openMonth')
            ->assertSet('actualTotal', '0.00')
            ->assertSet('remaining', '1000.00')
            ->assertSet('financialPosition.totalAssets', '2000.00')
            ->assertSeeText(['Sep 13 – Oct 12', 'Oct 20, 2026', 'BRL 125.00'])
            ->assertDontSeeText('Aug 13 – Sep 12');

        $this->assertSame('2000.00', $account->refresh()->current_balance);
    }
}
