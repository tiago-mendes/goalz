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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('currentMonthPacingProvider')]
    public function test_budget_report_calculates_current_month_pacing(string $spending, string $expectedStatus): void
    {
        $this->travelTo('2026-09-15');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        BudgetRule::factory()->for($user)->for($category, 'expenseCategory')->create(['amount' => '100.00']);
        Expense::factory()->for($user)->for($category)->create(['amount' => $spending, 'expense_date' => '2026-09-15']);

        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame('50.0', $report['monthElapsed']);
        $this->assertSame($expectedStatus, $report['overallPacingStatus']);
    }

    /** @return array<string, array{string, string}> */
    public static function currentMonthPacingProvider(): array
    {
        return [
            'below pace' => ['30.00', 'On track'],
            'at pace' => ['50.00', 'On track'],
            'above pace' => ['80.00', 'Above pace'],
            'full usage above pace' => ['100.00', 'Above pace'],
            'over budget takes priority' => ['110.00', 'Over budget'],
        ];
    }

    public function test_budget_report_applies_shared_current_month_pacing_to_each_category(): void
    {
        $this->travelTo('2026-09-15');
        $user = User::factory()->create();
        $expectedStatuses = [
            'Below pace' => ['30.00', 'On track'],
            'At pace' => ['50.00', 'On track'],
            'Food' => ['65.90', 'Above pace'],
            'Leisure' => ['62.00', 'Above pace'],
            'Market/Groceries' => ['41.30', 'On track'],
            'Over budget' => ['110.00', 'Over budget'],
        ];

        foreach ($expectedStatuses as $name => [$spending, $status]) {
            $category = ExpenseCategory::factory()->for($user)->create(['name' => $name]);
            BudgetRule::factory()->for($user)->for($category, 'expenseCategory')->create(['amount' => '100.00']);
            Expense::factory()->for($user)->for($category)->create(['amount' => $spending, 'expense_date' => '2026-09-15']);
        }

        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2026-09-01'));
        $rows = collect($report['rows'])->keyBy(fn (array $row): string => $row['category']->name);

        $this->assertSame('50.0', $report['monthElapsed']);
        foreach ($expectedStatuses as $name => [$spending, $status]) {
            $this->assertSame($status, $rows[$name]['status']);
            $this->assertSame(number_format((float) $spending, 1), $rows[$name]['usage']);
        }
    }

    public function test_budget_report_handles_month_lengths_and_historical_and_future_pacing(): void
    {
        $this->travelTo('2024-02-29');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        BudgetRule::factory()->for($user)->for($category, 'expenseCategory')->create(['amount' => '100.00', 'starts_month' => '2024-02-01']);

        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2024-02-01'));
        $this->assertSame('100.0', $report['monthElapsed']);
        $this->assertSame('On track', $report['overallPacingStatus']);

        $this->travelTo('2026-01-16');
        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2026-01-01'));
        $this->assertSame('51.6', $report['monthElapsed']);

        $this->travelTo('2026-02-16');
        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2026-01-01'));
        $this->assertSame('100.0', $report['monthElapsed']);
        $this->assertSame('On track', $report['overallPacingStatus']);

        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2026-03-01'));
        $this->assertSame('0.0', $report['monthElapsed']);
        $this->assertSame('Not started', $report['overallPacingStatus']);
    }

    #[DataProvider('historicalPacingProvider')]
    public function test_budget_report_never_marks_completed_months_above_pace(string $spending, string $expectedStatus): void
    {
        $this->travelTo('2026-02-16');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        BudgetRule::factory()->for($user)->for($category, 'expenseCategory')->create(['amount' => '100.00', 'starts_month' => '2026-01-01']);
        Expense::factory()->for($user)->for($category)->create(['amount' => $spending, 'expense_date' => '2026-01-15']);

        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2026-01-01'));

        $this->assertSame('100.0', $report['monthElapsed']);
        $this->assertSame($expectedStatus, $report['overallPacingStatus']);
    }

    /** @return array<string, array{string, string}> */
    public static function historicalPacingProvider(): array
    {
        return [
            'below budget' => ['80.00', 'On track'],
            'at budget' => ['100.00', 'On track'],
            'over budget' => ['110.00', 'Over budget'],
        ];
    }

    public function test_budget_report_preserves_future_usage_without_claiming_pacing(): void
    {
        $this->travelTo('2026-02-16');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        BudgetRule::factory()->for($user)->for($category, 'expenseCategory')->create(['amount' => '100.00', 'starts_month' => '2026-01-01']);
        Expense::factory()->for($user)->for($category)->create(['amount' => '110.00', 'expense_date' => '2026-03-15']);

        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2026-03-01'));

        $this->assertSame('110.0', $report['overallUsage']);
        $this->assertSame('0.0', $report['monthElapsed']);
        $this->assertSame('Not started', $report['overallPacingStatus']);
    }

    public function test_budget_report_applies_not_started_status_to_each_future_category(): void
    {
        $this->travelTo('2026-02-16');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Future Food']);
        BudgetRule::factory()->for($user)->for($category, 'expenseCategory')->create(['amount' => '100.00', 'starts_month' => '2026-01-01']);
        Expense::factory()->for($user)->for($category)->create(['amount' => '25.00', 'expense_date' => '2026-03-15']);

        $report = app(BudgetReport::class)->handle($user, CarbonImmutable::parse('2026-03-01'));

        $this->assertSame('25.0', $report['rows'][0]['usage']);
        $this->assertSame('Not started', $report['rows'][0]['status']);
    }

    public function test_budget_report_renders_pacing_summary_for_current_month(): void
    {
        $this->travelTo('2026-09-15');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        BudgetRule::factory()->for($user)->for($category, 'expenseCategory')->create(['amount' => '100.00']);
        Expense::factory()->for($user)->for($category)->create(['amount' => '80.00', 'expense_date' => '2026-09-15']);
        $this->actingAs($user);

        Livewire::test('pages::reports.budgets')
            ->assertSeeText('Overall Usage')
            ->assertSeeText('80.0%')
            ->assertSeeText('Month elapsed: 50.0%')
            ->assertSeeText('Above pace');
    }

    public function test_budget_report_renders_the_shared_elapsed_marker_on_each_category_row(): void
    {
        $this->travelTo('2026-09-15');
        $user = User::factory()->create();

        foreach ([['Food', '65.90'], ['Leisure', '62.00'], ['Market/Groceries', '41.30']] as [$name, $spending]) {
            $category = ExpenseCategory::factory()->for($user)->create(['name' => $name]);
            BudgetRule::factory()->for($user)->for($category, 'expenseCategory')->create(['amount' => '100.00']);
            Expense::factory()->for($user)->for($category)->create(['amount' => $spending, 'expense_date' => '2026-09-15']);
        }

        $this->actingAs($user);
        $html = Livewire::test('pages::reports.budgets')->html();

        $this->assertStringContainsString('65.9%', $html);
        $this->assertStringContainsString('Above pace', $html);
        $this->assertStringContainsString('41.3%', $html);
        $this->assertStringContainsString('On track', $html);
        $this->assertSame(3, substr_count($html, 'title="Month elapsed: 50.0%"'));
        $this->assertSame(4, substr_count($html, 'role="progressbar"'));
    }

    public function test_budget_report_does_not_show_pacing_status_without_a_budget(): void
    {
        $this->travelTo('2026-09-15');
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::reports.budgets')
            ->assertSeeText('No budgets configured')
            ->assertSeeText('No budget configured')
            ->assertDontSeeText('On track');
    }

    #[DataProvider('invalidReportMonthProvider')]
    public function test_budget_report_rejects_invalid_query_string_months(string $month): void
    {
        $this->travelTo('2026-09-15');
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['month' => $month])
            ->test('pages::reports.budgets')
            ->assertSet('month', '2026-09')
            ->assertSet('selectedMonth', '2026-09');
    }

    /** @return array<string, array{string}> */
    public static function invalidReportMonthProvider(): array
    {
        return [
            'empty' => [''],
            'month zero' => ['2026-00'],
            'month thirteen' => ['2026-13'],
            'malformed' => ['not-a-month'],
            'below supported range' => ['0999-12'],
            'above supported range' => ['10000-01'],
        ];
    }

    public function test_budget_report_selected_month_cannot_be_tampered_with(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::reports.budgets');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('selectedMonth', '2026-13');
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
        $active = ExpenseCategory::factory()->for($user)->create(['name' => 'Food', 'icon' => 'shopping-cart']);
        $foreign = ExpenseCategory::factory()->create(['name' => 'Private']);
        ExpenseCategory::factory()->for($user)->inactive()->create(['name' => 'Archived']);
        $this->actingAs($user);

        $this->get(route('budgets.create'))->assertOk()->assertSeeText('Food')->assertDontSeeText('Private')->assertDontSeeText('Archived');
        Livewire::test('pages::budgets.form')->assertSee($active->name)->assertSee('data-flux-icon', false)->assertDontSee($foreign->name);
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
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Historical', 'icon' => 'heart']);
        $budget = app(SaveBudget::class)->handle($user, $category, '500.00', BudgetMode::Recurring, '2026-09');
        $category->update(['is_active' => false]);
        $this->actingAs($user);

        $this->get(route('budgets.edit', $budget->id))->assertOk()->assertSeeText('Historical');
        Livewire::test('pages::budgets.form', ['budgetId' => $budget->id])
            ->assertSee($category->name)->assertSee('aria-label="Historical"', false)
            ->assertSee('color: '.$category->safeColor(), false)->assertSee('data-flux-icon', false);
    }

    public function test_budget_form_selection_saves_the_selected_owned_category(): void
    {
        $this->travelTo('2026-09-09');
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        $this->actingAs($user);

        Livewire::test('pages::budgets.form')
            ->set('expense_category_id', $category->id)
            ->set('amount', '500.00')
            ->set('startsMonth', '2026-09')
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('budget_rules', [
            'user_id' => $user->id,
            'expense_category_id' => $category->id,
            'amount' => '500.00',
        ]);
    }
}
