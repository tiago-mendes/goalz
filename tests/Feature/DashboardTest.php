<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use App\Models\MonthlyIncome;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        $response->assertOk();
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
        $this->assertFalse(Schema::hasTable('expenses'));
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
            ->assertSee('September 2026')->assertSet('plannedTotal', '0.00')->assertSet('remaining', '500.00')
            ->assertDontSee('October cost');
        $page->set('month', '2026-10')->call('openMonth')->assertHasNoErrors()
            ->assertSee('October 2026')->assertSee('October cost')->assertSee('1000.00')
            ->assertSet('plannedTotal', '10.25')->assertSet('remaining', '989.75');
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
            ->assertSet('remaining', null)->assertSet('plannedTotal', '0.30');

        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    public function test_zero_default_is_valid_and_negative_remaining_is_exact(): void
    {
        $user = User::factory()->create(['default_monthly_income' => '0.00', 'currency' => 'BRL']);
        FixedExpense::factory()->for($user)->create(['amount' => '0.10', 'start_date' => '1000-01-01']);
        FixedExpense::factory()->for($user)->create(['amount' => '0.20', 'start_date' => '1000-01-01']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->assertSee('BRL 0.00')->assertSet('plannedTotal', '0.30')
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
            ->assertDontSee('Too early')->assertDontSee('Inactive bill')->assertSee('On start date')->assertSet('plannedTotal', '20.00')
            ->set('month', '2026-10')->call('openMonth')->assertSee('Too early')->assertSet('plannedTotal', '30.00')
            ->set('month', '2025-01')->call('openMonth')->assertDontSee('Inactive bill')->assertSet('plannedTotal', '0.00')
            ->assertSee('No planned fixed expenses for this month');
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
        FixedExpense::factory()->for($user)->create(['name' => '<script>alert(1)</script>', 'expense_category_id' => $category->id, 'start_date' => '1000-01-01']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->assertSee('Category unavailable')->assertDontSee('Foreign category')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
