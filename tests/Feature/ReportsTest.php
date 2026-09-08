<?php

namespace Tests\Feature;

use App\Actions\MaterializeFixedExpensesForMonth;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use App\Models\MonthlyIncome;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_root_and_sidebar_default_to_goals_reports(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/reports')->assertRedirect(route('reports.goals'));
        $this->get(route('dashboard'))->assertSee('href="'.route('reports.goals').'"', false);
    }

    public function test_report_navigation_has_the_shared_order_on_each_report(): void
    {
        $this->actingAs(User::factory()->create());
        $routes = ['reports.goals', 'reports.budgets', 'reports.assets', 'reports.credit-cards', 'reports.cash-flow'];
        $labels = ['Goals', 'Budgets', 'Assets', 'Credit Cards', 'Cash Flow'];

        foreach ($routes as $route) {
            $content = $this->get(route($route))->assertOk()->getContent();
            $navStart = strpos($content, '<nav aria-label="Reports"');
            $this->assertNotFalse($navStart);
            $nav = preg_replace('/\s+/', ' ', strip_tags(substr($content, $navStart, strpos($content, '</nav>', $navStart) - $navStart)));
            $positions = [];
            foreach ($labels as $label) {
                $position = strpos($nav, $label);
                $this->assertNotFalse($position);
                $positions[] = $position;
            }
            $sortedPositions = $positions;
            sort($sortedPositions);

            $this->assertSame($sortedPositions, $positions);
        }
    }

    public function test_reports_default_to_the_last_six_calendar_months(): void
    {
        $this->travelTo('2026-09-07');
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::reports.cash-flow')
            ->assertSet('from', '2026-04')
            ->assertSet('to', '2026-09')
            ->assertSet('selectedFrom', '2026-04')
            ->assertSet('selectedTo', '2026-09');
    }

    public function test_reports_validate_period_and_preserve_valid_query_parameters(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        $this->actingAs($user);

        $this->get(route('reports.cash-flow', ['from' => '2025-01', 'to' => '2025-02', 'categoryId' => $category->id]))
            ->assertOk()->assertSeeText('January 2025')->assertSeeText('February 2025');

        Livewire::withQueryParams([
            'from' => '2025-01',
            'to' => '2025-02',
            'categoryId' => $category->id,
        ])->test('pages::reports.cash-flow')
            ->assertSet('from', '2025-01')
            ->assertSet('to', '2025-02')
            ->assertSet('categoryId', $category->id)
            ->assertSet('selectedFrom', '2025-01')
            ->assertSet('selectedTo', '2025-02');

        Livewire::withQueryParams([])->test('pages::reports.cash-flow')
            ->set('from', '2026-02')->set('to', '2026-01')->call('applyPeriod')
            ->assertHasErrors('to')->assertSet('selectedFrom', now()->subMonths(5)->format('Y-m'));
    }

    public function test_reports_reject_malformed_and_excessive_periods(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::reports.cash-flow');

        $page->set('from', '2026-1')->set('to', '2026-02')->call('applyPeriod')->assertHasErrors('from');
        $page->set('from', '2020-01')->set('to', '2026-01')->call('applyPeriod')->assertHasErrors('to');
    }

    public function test_reports_show_exact_monthly_income_expenses_remaining_and_missing_income(): void
    {
        $user = User::factory()->create(['default_monthly_income' => '9999.99']);
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        MonthlyIncome::factory()->for($user)->create(['year' => 2026, 'month' => 1, 'amount' => '100.10']);
        Expense::factory()->for($user)->create(['expense_category_id' => $category->id, 'expense_date' => '2026-01-10', 'amount' => '10.10']);
        Expense::factory()->for($user)->create(['expense_category_id' => $category->id, 'expense_date' => '2026-02-10', 'amount' => '20.20']);
        Expense::factory()->for($user)->deleted()->create(['expense_category_id' => $category->id, 'expense_date' => '2026-01-11', 'amount' => '90.00']);
        $this->actingAs($user);

        Livewire::test('pages::reports.cash-flow')
            ->set('from', '2026-01')->set('to', '2026-02')->call('applyPeriod')
            ->assertSet('report.months.0.income', '100.10')
            ->assertSet('report.months.0.expenses', '10.10')
            ->assertSet('report.months.0.remaining', '90.00')
            ->assertSet('report.months.1.income', null)
            ->assertSet('report.months.1.expenses', '20.20')
            ->assertSet('report.months.1.remaining', null)
            ->assertSeeText('—');

        $this->assertDatabaseCount('monthly_incomes', 1);
        $this->assertDatabaseCount('expenses', 3);
    }

    public function test_reports_group_categories_and_sources_using_persisted_expenses(): void
    {
        $user = User::factory()->create();
        $food = ExpenseCategory::factory()->for($user)->create(['name' => 'Food', 'is_active' => false]);
        $travel = ExpenseCategory::factory()->for($user)->create(['name' => 'Travel']);
        Expense::factory()->for($user)->create(['expense_category_id' => $food->id, 'amount' => '10.10', 'expense_date' => '2026-01-10']);
        Expense::factory()->for($user)->create(['expense_category_id' => $travel->id, 'amount' => '20.20', 'expense_date' => '2026-01-10']);
        $this->actingAs($user);

        $report = Livewire::test('pages::reports.cash-flow')
            ->set('from', '2026-01')->set('to', '2026-02')->call('applyPeriod')
            ->get('report');

        $this->assertSame('30.30', $report['months'][0]['expenses']);
        $this->assertSame('30.30', $report['months'][0]['manual']);
        $this->assertSame('20.20', $report['categories'][0]['amount']);
        $this->assertSame('33.3', $report['categories'][1]['percentage']);
        $this->assertSame('10.10', $report['categoryEvolution'][0]['amount']);
        $this->assertSame('0.00', $report['categoryEvolution'][1]['amount']);
    }

    public function test_reports_seed_all_chart_payloads_with_numeric_values_and_a_valid_category(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        Expense::factory()->for($user)->create([
            'expense_category_id' => $category->id,
            'amount' => '12.34',
            'expense_date' => '2026-01-10',
        ]);
        MonthlyIncome::factory()->for($user)->create(['year' => 2026, 'month' => 1, 'amount' => '100.00']);
        $this->actingAs($user);

        $page = Livewire::test('pages::reports.cash-flow')
            ->set('from', '2026-01')
            ->set('to', '2026-02')
            ->call('applyPeriod');

        $chartData = $page->get('chartData');

        $this->assertSame($category->id, $page->get('categoryId'));
        $this->assertSame(['January 2026', 'February 2026'], $chartData['monthly']['labels']);
        $this->assertSame([100.0, null], $chartData['monthly']['income']);
        $this->assertSame([12.34, 0.0], $chartData['monthly']['expenses']);
        $this->assertSame(['Food'], $chartData['categories']['labels']);
        $this->assertSame([12.34], $chartData['categories']['amounts']);
        $this->assertSame([12.34, 0.0], $chartData['sources']['manual']);
        $this->assertSame([0.0, 0.0], $chartData['sources']['recurring']);
        $this->assertSame([12.34, 0.0], $chartData['evolution']['amounts']);
    }

    public function test_reports_fall_back_to_an_owned_category_when_selection_is_stale(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        $this->actingAs($user);

        Livewire::test('pages::reports.cash-flow')
            ->set('categoryId', 999999)
            ->assertHasErrors('categoryId')
            ->assertSet('categoryId', $category->id);
    }

    public function test_reports_allow_inactive_owned_categories_for_evolution(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Historical', 'is_active' => false]);
        Expense::factory()->for($user)->create([
            'expense_category_id' => $category->id,
            'amount' => '10.00',
            'expense_date' => '2026-01-10',
        ]);
        $this->actingAs($user);

        Livewire::test('pages::reports.cash-flow')
            ->set('from', '2026-01')
            ->set('to', '2026-01')
            ->call('applyPeriod')
            ->set('categoryId', $category->id)
            ->assertHasNoErrors('categoryId')
            ->assertSet('report.categoryEvolution.0.amount', '10.00');
    }

    public function test_reports_split_persisted_recurring_and_manual_expenses(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        FixedExpense::factory()->for($user)->create(['expense_category_id' => $category->id, 'amount' => '60.70', 'start_date' => '2026-01-01']);
        Expense::factory()->for($user)->create(['expense_category_id' => $category->id, 'amount' => '40.30', 'expense_date' => '2026-01-15']);
        app(MaterializeFixedExpensesForMonth::class)->handle($user, 2026, 1);
        $this->actingAs($user);

        $report = Livewire::test('pages::reports.cash-flow')
            ->set('from', '2026-01')->set('to', '2026-01')->call('applyPeriod')
            ->get('report');

        $this->assertSame('60.70', $report['months'][0]['recurring']);
        $this->assertSame('40.30', $report['months'][0]['manual']);
        $this->assertSame('101.00', $report['months'][0]['expenses']);
    }

    public function test_reports_are_read_only_and_never_materialize_missing_history(): void
    {
        $user = User::factory()->create(['default_monthly_income' => '500.00']);
        FixedExpense::factory()->for($user)->create(['start_date' => '2025-01-01']);
        $this->actingAs($user);

        Livewire::test('pages::reports.cash-flow')
            ->set('from', '2025-01')->set('to', '2025-02')->call('applyPeriod');

        $this->assertDatabaseCount('monthly_incomes', 0);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_reports_exclude_foreign_data_and_reject_a_foreign_category(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $ownCategory = ExpenseCategory::factory()->for($user)->create(['name' => 'Own']);
        $foreignCategory = ExpenseCategory::factory()->for($other)->create(['name' => 'Private']);
        Expense::factory()->for($user)->create(['expense_category_id' => $ownCategory->id, 'amount' => '1.00']);
        Expense::factory()->for($other)->create(['expense_category_id' => $foreignCategory->id, 'amount' => '999.00']);
        MonthlyIncome::factory()->for($other)->create(['year' => 2026, 'month' => 9, 'amount' => '999.00']);
        $this->actingAs($user);

        Livewire::test('pages::reports.cash-flow')
            ->set('from', '2026-09')->set('to', '2026-09')->call('applyPeriod')
            ->set('categoryId', $foreignCategory->id)
            ->assertHasErrors('categoryId')
            ->assertSet('categoryId', $ownCategory->id)
            ->assertDontSeeText('Private')
            ->assertDontSeeText('999.00')
            ->assertSet('report.months.0.expenses', '1.00');
    }
}
