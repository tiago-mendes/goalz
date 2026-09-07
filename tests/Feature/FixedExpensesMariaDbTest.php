<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Run only this file against MariaDB after migrating. Each test rolls back its
 * fixture records; this class never migrates or refreshes the runtime database.
 */
class FixedExpensesMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify its decimal storage and constraints.');
        }
    }

    public function test_maximum_decimal_round_trips_through_create_and_edit(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        Livewire::test('pages::fixed-expenses.form')->set([
            'name' => 'Precision check', 'expense_category_id' => $category->id,
            'amount' => '9999999999999.99', 'day_of_month' => 31, 'start_date' => '2026-09-01',
        ])->call('save')->assertHasNoErrors();
        $expense = $category->user->fixedExpenses()->sole();
        $this->assertSame('9999999999999.99', $expense->amount);
        $this->assertSame('9999999999999.99', $expense->getRawOriginal('amount'));
        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $expense->id])
            ->assertSet('amount', '9999999999999.99')->set('name', 'Updated precision check')->call('save')->assertHasNoErrors();
        $this->assertSame('9999999999999.99', $expense->refresh()->amount);
    }

    #[TestWith([0])]
    #[TestWith([32])]
    public function test_database_rejects_out_of_range_days(int $day): void
    {
        $expense = FixedExpense::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('fixed_expenses')->where('id', $expense->id)->update(['day_of_month' => $day]);
    }

    #[TestWith(['user_id'])]
    #[TestWith(['expense_category_id'])]
    public function test_database_rejects_missing_foreign_keys(string $column): void
    {
        $expense = FixedExpense::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('fixed_expenses')->where('id', $expense->id)->update([$column => 0]);
    }

    public function test_form_enforces_category_ownership_on_mariadb(): void
    {
        $expense = FixedExpense::factory()->create();
        $foreignCategory = ExpenseCategory::factory()->create();
        $originalCategory = $expense->expense_category_id;
        $this->actingAs($expense->user);
        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $expense->id])
            ->set('expense_category_id', $foreignCategory->id)->call('save')->assertHasErrors(['expense_category_id' => 'exists']);
        $this->assertSame($originalCategory, $expense->refresh()->expense_category_id);
        $this->assertSame($expense->user_id, $expense->expenseCategory->user_id);
    }

    public function test_monthly_overview_preserves_large_decimal_totals_and_remaining(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $category = ExpenseCategory::factory()->create();
        $user = $category->user;
        $user->default_monthly_income = '9999999999999.99';
        $user->save();
        FixedExpense::factory()->for($user)->for($category)->count(2)->create(['amount' => '9999999999999.99']);
        FixedExpense::factory()->for($user)->for($category)->create(['amount' => '0.03']);
        $this->actingAs($user);

        Livewire::test('pages::dashboard')->assertSet('actualTotal', '20000000000000.01')
            ->assertSet('remaining', '-10000000000000.02')->assertSee('9999999999999.99');

        $this->assertSame('9999999999999.99', $user->monthlyIncomes()->sole()->amount);
    }
}
