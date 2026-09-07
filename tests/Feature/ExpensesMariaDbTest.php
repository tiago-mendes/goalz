<?php

namespace Tests\Feature;

use App\Actions\MaterializeFixedExpensesForMonth;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/** Runs against migrated MariaDB with every fixture rolled back. */
class ExpensesMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB decimal storage and constraints.');
        }
    }

    public function test_account_deletion_cleans_owned_expenses_before_restrictive_references(): void
    {
        $fixed = FixedExpense::factory()->create();
        app(MaterializeFixedExpensesForMonth::class)->handle($fixed->user, 2026, 9);
        Expense::factory()->for($fixed->user)->deleted()->create();
        $foreign = Expense::factory()->create();
        $this->actingAs($fixed->user);

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')
            ->assertHasNoErrors()->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $fixed->user_id]);
        $this->assertDatabaseMissing('expenses', ['user_id' => $fixed->user_id]);
        $this->assertDatabaseMissing('fixed_expenses', ['user_id' => $fixed->user_id]);
        $this->assertModelExists($foreign);
        $this->assertGuest();
    }

    public function test_maximum_decimal_round_trips_and_deleted_duplicate_matches_exactly(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        $fields = ['name' => 'Precision', 'expense_category_id' => $category->id, 'amount' => '9999999999999.99', 'expense_date' => '2026-09-10'];
        Livewire::test('pages::expenses.form')->set($fields)->call('save')->assertHasNoErrors();
        $expense = $category->user->expenses()->sole();
        $this->assertSame('9999999999999.99', $expense->getRawOriginal('amount'));
        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->set('description', ' Updated ')->call('save')->assertHasNoErrors();
        $this->assertSame('9999999999999.99', $expense->refresh()->amount);
        $expense->deleted_by_user_at = now();
        $expense->save();
        Livewire::test('pages::expenses.form')->set($fields + ['description' => 'Updated'])->call('save')->assertSet('duplicateId', $expense->id);
        Livewire::test('pages::expenses.form')->set(array_replace($fields, ['name' => 'precision', 'description' => 'Updated']))
            ->call('save')->assertSet('duplicateId', null);
        $this->assertSame(2, $category->user->expenses()->count());
    }

    public function test_large_recurring_snapshots_and_total_remain_exact(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $fixed = FixedExpense::factory()->create(['amount' => '9999999999999.99']);
        FixedExpense::factory()->for($fixed->user)->create(['amount' => '9999999999999.99']);
        $this->actingAs($fixed->user);
        Livewire::test('pages::expenses.index')->assertSet('total', '19999999999999.98');
        $this->assertSame('9999999999999.99', $fixed->expenses()->sole()->amount);
    }

    public function test_deleted_recurring_identity_blocks_materialization_and_direct_duplicate(): void
    {
        $fixed = FixedExpense::factory()->create();
        $action = app(MaterializeFixedExpensesForMonth::class);
        $action->handle($fixed->user, 2026, 9);
        $expense = $fixed->expenses()->sole();
        $expense->deleted_by_user_at = now();
        $expense->save();
        $action->handle($fixed->user, 2026, 9);
        $this->assertSame($expense->id, $fixed->expenses()->sole()->id);
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);

        $this->expectException(QueryException::class);
        Expense::factory()->for($fixed->user)->create(['fixed_expense_id' => $fixed->id, 'occurrence_year' => 2026, 'occurrence_month' => 9]);
    }

    public function test_multiple_manual_expenses_with_null_identity_are_allowed(): void
    {
        $category = ExpenseCategory::factory()->create();
        Expense::factory()->for($category->user)->for($category)->count(2)->create();
        $this->assertSame(2, $category->user->expenses()->count());
    }

    #[TestWith(['occurrence_year', 2026])]
    #[TestWith(['occurrence_month', 9])]
    public function test_manual_metadata_must_be_null(string $field, int $value): void
    {
        $expense = Expense::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('expenses')->where('id', $expense->id)->update([$field => $value]);
    }

    #[TestWith([null, 9])]
    #[TestWith([2026, null])]
    #[TestWith([2026, 0])]
    #[TestWith([2026, 13])]
    #[TestWith([999, 9])]
    #[TestWith([10000, 9])]
    public function test_recurring_metadata_must_be_complete_and_in_range(?int $year, ?int $month): void
    {
        $fixed = FixedExpense::factory()->create();
        $this->expectException(QueryException::class);
        Expense::factory()->for($fixed->user)->create(['fixed_expense_id' => $fixed->id, 'occurrence_year' => $year, 'occurrence_month' => $month]);
    }

    #[TestWith(['user_id'])]
    #[TestWith(['expense_category_id'])]
    #[TestWith(['fixed_expense_id'])]
    public function test_foreign_keys_must_exist(string $field): void
    {
        $fixed = FixedExpense::factory()->create();
        app(MaterializeFixedExpensesForMonth::class)->handle($fixed->user, 2026, 9);
        $expense = $fixed->expenses()->sole();
        $this->expectException(QueryException::class);
        DB::table('expenses')->where('id', $expense->id)->update([$field => 0]);
    }

    #[TestWith(['expenseCategory'])]
    #[TestWith(['fixedExpense'])]
    #[TestWith(['user'])]
    public function test_referenced_records_cannot_be_deleted_with_expense_history(string $relation): void
    {
        $fixed = FixedExpense::factory()->create();
        app(MaterializeFixedExpensesForMonth::class)->handle($fixed->user, 2026, 9);
        $expense = $fixed->expenses()->sole();
        $this->expectException(QueryException::class);
        $expense->$relation->delete();
    }

    #[TestWith(['0.00'])]
    #[TestWith(['-0.01'])]
    public function test_amount_must_be_positive_at_database_boundary(string $amount): void
    {
        $this->expectException(QueryException::class);
        Expense::factory()->create(['amount' => $amount]);
    }
}
