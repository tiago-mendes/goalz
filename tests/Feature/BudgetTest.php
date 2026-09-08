<?php

namespace Tests\Feature;

use App\Actions\SaveBudget;
use App\Actions\StopBudget;
use App\BudgetMode;
use App\BudgetResolver;
use App\Models\BudgetRule;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use App\Models\MonthlyIncome;
use App\Models\User;
use App\Reports\BudgetReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurring_and_selected_budgets_resolve_only_for_applicable_months(): void
    {
        $this->travelTo('2026-09-08');
        $user = User::factory()->create();
        $food = ExpenseCategory::factory()->for($user)->create();
        $travel = ExpenseCategory::factory()->for($user)->create();
        app(SaveBudget::class)->handle($user, $food, '1500.00', BudgetMode::Recurring, '2026-09');
        app(SaveBudget::class)->handle($user, $travel, '2000.00', BudgetMode::SelectedMonths, '2026-09', ['2027-06', '2027-08']);
        $resolver = app(BudgetResolver::class);
        $this->assertNull($resolver->forMonth($user, $food, '2026-08'));
        $this->assertSame('1500.00', $resolver->forMonth($user, $food, '2027-01')?->amount);
        $this->assertSame('2000.00', $resolver->forMonth($user, $travel, '2027-06')?->amount);
        $this->assertNull($resolver->forMonth($user, $travel, '2027-07'));
        $this->assertNull($resolver->forMonth(User::factory()->create(), $food, '2026-09'));
    }

    public function test_future_edit_creates_a_version_without_rewriting_historical_months(): void
    {
        $this->travelTo('2026-11-08');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        $old = app(SaveBudget::class)->handle($user, $category, '1500.00', BudgetMode::Recurring, '2026-09');
        app(SaveBudget::class)->handle($user, $category, '2000.00', BudgetMode::Recurring, '2026-11', [], $old);
        $resolver = app(BudgetResolver::class);
        $this->assertSame('1500.00', $resolver->forMonth($user, $category, '2026-09')?->amount);
        $this->assertSame('1500.00', $resolver->forMonth($user, $category, '2026-10')?->amount);
        $this->assertSame('2000.00', $resolver->forMonth($user, $category, '2026-11')?->amount);
        $this->assertSame('2000.00', $resolver->forMonth($user, $category, '2026-12')?->amount);
    }

    public function test_selected_month_edit_preserves_past_rows(): void
    {
        $this->travelTo('2026-10-08');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        $old = app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::SelectedMonths, '2026-09', ['2026-09', '2026-10']);
        app(SaveBudget::class)->handle($user, $category, '700.00', BudgetMode::SelectedMonths, '2026-10', ['2026-10', '2026-12'], $old);
        $resolver = app(BudgetResolver::class);
        $this->assertSame('500.00', $resolver->forMonth($user, $category, '2026-09')?->amount);
        $this->assertSame('700.00', $resolver->forMonth($user, $category, '2026-10')?->amount);
        $this->assertSame('700.00', $resolver->forMonth($user, $category, '2026-12')?->amount);
    }

    public function test_overlapping_rules_are_rejected(): void
    {
        $this->travelTo('2026-09-08');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::Recurring, '2026-09');
        $this->expectException(ValidationException::class);
        app(SaveBudget::class)->handle($user, $category, '700.00', BudgetMode::SelectedMonths, '2026-09', ['2026-10']);
    }

    public function test_overlapping_recurring_rules_are_rejected(): void
    {
        $this->travelTo('2026-09-08');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::Recurring, '2026-09');

        $this->expectException(ValidationException::class);
        app(SaveBudget::class)->handle($user, $category, '700.00', BudgetMode::Recurring, '2026-10');
    }

    public function test_overlapping_selected_months_are_rejected(): void
    {
        $this->travelTo('2026-09-08');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::SelectedMonths, '2026-09', ['2026-10']);

        $this->expectException(ValidationException::class);
        app(SaveBudget::class)->handle($user, $category, '700.00', BudgetMode::SelectedMonths, '2026-09', ['2026-10', '2026-12']);
    }

    public function test_budget_report_uses_persisted_expenses_exactly_and_is_read_only(): void
    {
        $this->travelTo('2026-09-08');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::Recurring, '2026-09');
        Expense::factory()->for($user)->create(['expense_category_id' => $category->id, 'amount' => '620.00', 'expense_date' => '2026-09-10']);
        Expense::factory()->for($user)->deleted()->create(['expense_category_id' => $category->id, 'amount' => '99.99', 'expense_date' => '2026-09-11']);
        FixedExpense::factory()->for($user)->create(['expense_category_id' => $category->id, 'amount' => '40.00', 'start_date' => '2026-09-01']);
        $before = [BudgetRule::count(), Expense::count(), FixedExpense::count(), MonthlyIncome::count()];
        $report = app(BudgetReport::class)->handle($user, now()->startOfMonth());
        $this->assertSame('500.00', $report['totalBudget']);
        $this->assertSame('620.00', $report['budgetedSpending']);
        $this->assertSame('-120.00', $report['remaining']);
        $this->assertSame('124.0', $report['rows'][0]['usage']);
        $this->assertSame('Over budget', $report['rows'][0]['status']);
        $this->assertSame($before, [BudgetRule::count(), Expense::count(), FixedExpense::count(), MonthlyIncome::count()]);
    }

    public function test_management_rejects_foreign_category_and_invalid_amount(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = ExpenseCategory::factory()->for($other)->create();
        $this->actingAs($user);
        $this->get(route('budgets.edit', $foreign->id))->assertNotFound();
        $this->assertDatabaseCount('budget_rules', 0);
    }

    public function test_stop_keeps_current_month_and_historical_budget(): void
    {
        $this->travelTo('2026-09-08');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        $rule = app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::Recurring, '2026-08');
        app(StopBudget::class)->handle($rule);
        $this->assertNotNull(app(BudgetResolver::class)->forMonth($user, $category, '2026-09'));
        $this->assertNull(app(BudgetResolver::class)->forMonth($user, $category, '2026-10'));
    }

    public function test_deleting_a_user_cascades_budget_rules_and_selected_months(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::SelectedMonths, '2026-09', ['2026-09', '2026-10']);

        $user->delete();

        $this->assertDatabaseCount('budget_rules', 0);
        $this->assertDatabaseCount('budget_rule_months', 0);
    }

    public function test_budget_create_page_renders_owned_active_categories(): void
    {
        $user = User::factory()->create();
        $active = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        $foreign = ExpenseCategory::factory()->create(['name' => 'Private']);
        ExpenseCategory::factory()->for($user)->inactive()->create(['name' => 'Archived']);
        $this->actingAs($user);

        $this->get(route('budgets.create'))->assertOk()->assertSeeText('Food')->assertDontSeeText('Private')->assertDontSeeText('Archived');
        Livewire::test('pages::budgets.form')->assertSee($active->name)->assertDontSee($foreign->name);
    }

    public function test_budget_form_does_not_offer_inactive_category_for_create(): void
    {
        $user = User::factory()->create();
        $inactive = ExpenseCategory::factory()->for($user)->inactive()->create(['name' => 'Archived']);
        $this->actingAs($user);

        Livewire::test('pages::budgets.form')->assertDontSee($inactive->name);
    }

    public function test_budget_edit_keeps_an_inactive_historical_category_selectable(): void
    {
        $this->travelTo('2026-09-08');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Historical']);
        $budget = app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::Recurring, '2026-09');
        $category->update(['is_active' => false]);
        $this->actingAs($user);

        $this->get(route('budgets.edit', $budget->id))->assertOk()->assertSeeText('Historical');
        Livewire::test('pages::budgets.form', ['budgetId' => $budget->id])->assertSee($category->name);
    }
}
