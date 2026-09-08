<?php

namespace Tests\Feature;

use App\Actions\MaterializeFixedExpensesForMonth;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_expenses(): void
    {
        foreach (['/expenses', '/expenses/create', '/expenses/1/edit'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
        Livewire::test('pages::expenses.index')->assertForbidden();
        Livewire::test('pages::expenses.form')->assertForbidden();
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_lists_and_mutations_are_owner_scoped_without_admin_bypass(string $role): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $user = User::factory()->create(['role' => $role, 'currency' => 'BRL']);
        $own = Expense::factory()->for($user)->create(['name' => 'Own bill', 'amount' => '10.50']);
        $foreign = Expense::factory()->create(['name' => 'Foreign secret', 'amount' => '8765.43']);
        $deleted = Expense::factory()->deleted()->create(['name' => 'Foreign deleted']);
        FixedExpense::factory()->create(['name' => 'Foreign template']);
        $this->actingAs($user);

        $this->get('/expenses')->assertSee('Own bill')->assertSee('R$ 10.50')->assertDontSee('Foreign secret')->assertDontSee('8765.43');
        $this->get(route('expenses.edit', $foreign))->assertNotFound();
        Livewire::test('pages::expenses.form', ['expenseId' => $foreign->id])->assertNotFound();
        Livewire::test('pages::expenses.index')->set('showDeleted', true)->assertDontSee('Foreign deleted')->assertDontSee('Foreign template')
            ->call('deleteExpense', $foreign->id)->assertNotFound();
        Livewire::test('pages::expenses.index')->call('restoreExpense', $deleted->id)->assertNotFound();
        foreach (['view', 'update', 'delete', 'restore'] as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $own));
            $this->assertSame(404, Gate::forUser($user)->inspect($ability, $foreign)->status());
        }
        $this->assertFalse(Gate::forUser($user)->allows('forceDelete', $own));
        $this->assertNull($foreign->refresh()->deleted_by_user_at);
        $this->assertNotNull($deleted->refresh()->deleted_by_user_at);
        $this->assertDatabaseCount('expenses', 3);
    }

    public function test_manual_creation_normalizes_fields_and_preserves_legitimate_duplicates(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);

        for ($i = 0; $i < 2; $i++) {
            Livewire::test('pages::expenses.form')->set($this->fields($category, ['name' => '  Lunch  ', 'amount' => '100', 'description' => " \n "]))
                ->call('save', ['user_id' => 999, 'fixed_expense_id' => 99, 'occurrence_year' => 2020, 'deleted_by_user_at' => now()])
                ->assertHasNoErrors()->assertRedirect(route('expenses.index'));
        }

        $this->assertDatabaseCount('expenses', 2);
        foreach ($category->user->expenses()->get() as $expense) {
            $this->assertSame('Lunch', $expense->name);
            $this->assertSame('100.00', $expense->amount);
            $this->assertNull($expense->description);
            $this->assertNull($expense->fixed_expense_id);
            $this->assertNull($expense->occurrence_year);
            $this->assertNull($expense->occurrence_month);
            $this->assertNull($expense->deleted_by_user_at);
            $this->assertTrue($expense->expenseCategory->is($category));
            $this->assertTrue($expense->user->is($category->user));
        }
        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    public function test_edit_can_keep_an_inactive_category_and_move_a_manual_date(): void
    {
        $expense = Expense::factory()->create();
        $expense->expenseCategory->update(['is_active' => false]);
        $this->actingAs($expense->user);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])
            ->set(['name' => '  Updated  ', 'amount' => '0.01', 'description' => '  0  ', 'expense_date' => '2026-10-01'])
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Updated', $expense->refresh()->name);
        $this->assertSame('0', $expense->description);
        $this->assertSame('0.01', $expense->amount);
        $this->assertSame('2026-10-01', $expense->expense_date->toDateString());
        $this->assertFalse($expense->expenseCategory->is_active);
    }

    public function test_categories_must_be_owned_and_active_unless_retained_on_edit(): void
    {
        $expense = Expense::factory()->create();
        $inactive = ExpenseCategory::factory()->for($expense->user)->create(['is_active' => false]);
        $foreign = ExpenseCategory::factory()->create();
        $active = ExpenseCategory::factory()->for($expense->user)->create();
        $originalCategoryId = $expense->expense_category_id;
        $this->actingAs($expense->user);

        foreach ([$inactive, $foreign] as $category) {
            Livewire::test('pages::expenses.form')->set($this->fields($category))->call('save')->assertHasErrors('expense_category_id');
            Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->set('expense_category_id', $category->id)
                ->call('save')->assertHasErrors('expense_category_id');
        }
        $this->assertSame($originalCategoryId, $expense->refresh()->expense_category_id);
        $this->assertDatabaseCount('expenses', 1);
        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->set('expense_category_id', $active->id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($active->id, $expense->refresh()->expense_category_id);
    }

    #[TestWith(['0'])]
    #[TestWith(['0.00'])]
    #[TestWith(['-1'])]
    #[TestWith(['1e3'])]
    #[TestWith(['0.001'])]
    #[TestWith(['10000000000000.00'])]
    #[TestWith(['NaN'])]
    #[TestWith(['Infinity'])]
    public function test_invalid_amounts_are_rejected_on_create_and_edit(string $amount): void
    {
        $expense = Expense::factory()->create();
        $this->actingAs($expense->user);

        Livewire::test('pages::expenses.form')->set($this->fields($expense->expenseCategory, ['amount' => $amount]))
            ->call('save')->assertHasErrors('amount');
        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->set('amount', $amount)->call('save')->assertHasErrors('amount');

        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame('100.00', $expense->refresh()->amount);
    }

    #[TestWith(['name', '  '])]
    #[TestWith(['expense_date', '2026-02-30'])]
    #[TestWith(['expense_date', 'invalid'])]
    #[TestWith(['expense_date', '0999-12-31'])]
    #[TestWith(['expense_date', '10000-01-01'])]
    public function test_invalid_fields_do_not_create_expenses(string $field, string $value): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);

        Livewire::test('pages::expenses.form')->set($this->fields($category, [$field => $value]))->call('save')->assertHasErrors($field);

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_name_length_and_required_fields_are_validated(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        Livewire::test('pages::expenses.form')->set('expense_date', '')->call('save')
            ->assertHasErrors(['name', 'expense_category_id', 'amount', 'expense_date']);
        Livewire::test('pages::expenses.form')->set($this->fields($category, ['name' => str_repeat('a', 101)]))
            ->call('save')->assertHasErrors(['name' => 'max']);
        $this->assertDatabaseCount('expenses', 0);
    }

    #[TestWith(['user_id'])]
    #[TestWith(['fixed_expense_id'])]
    #[TestWith(['occurrence_year'])]
    #[TestWith(['occurrence_month'])]
    #[TestWith(['deleted_by_user_at'])]
    public function test_system_fields_cannot_be_injected_in_livewire_or_mass_assignment(string $field): void
    {
        $expense = Expense::factory()->create();
        $original = $expense->getAttribute($field);
        $expense->fill([$field => 999]);
        $this->assertSame($original, $expense->getAttribute($field));
        $this->actingAs($expense->user);
        $page = Livewire::test('pages::expenses.form', ['expenseId' => $expense->id]);

        $this->expectException(PublicPropertyNotFoundException::class);
        $page->set($field, 999);
    }

    #[TestWith(['expenseId'])]
    #[TestWith(['duplicateId'])]
    public function test_form_ids_are_locked(string $field): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::expenses.form');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set($field, 999);
    }

    public function test_materialization_is_lazy_idempotent_and_preserves_historical_snapshots(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $fixed = FixedExpense::factory()->create(['name' => 'Old rent', 'amount' => '2500.00', 'day_of_month' => 10]);
        FixedExpense::factory()->for($fixed->user)->inactive()->create();
        $this->actingAs($fixed->user);
        $this->assertDatabaseCount('expenses', 0);

        $page = Livewire::test('pages::expenses.index')->assertSet('selectedMonth', '2026-09')->assertSee('Old rent')->call('openMonth');
        $snapshot = $fixed->expenses()->sole();
        $original = $snapshot->getRawOriginal();
        $replacement = ExpenseCategory::factory()->for($fixed->user)->create();
        $fixed->update(['name' => 'New rent', 'amount' => '2700.00', 'day_of_month' => 15, 'expense_category_id' => $replacement->id]);
        $page->call('openMonth')->assertSee('Old rent')->assertDontSee('New rent');
        $this->assertSame($original, $snapshot->refresh()->getRawOriginal());
        $page->set('month', '2026-10')->call('openMonth')->assertSee('New rent')->assertDontSee('Old rent');
        $new = $fixed->expenses()->where('occurrence_month', 10)->sole();
        $this->assertSame('2700.00', $new->amount);
        $this->assertSame('2026-10-15', $new->expense_date->toDateString());
        $this->assertSame($replacement->id, $new->expense_category_id);
        $this->assertNull($new->description);
        $this->assertTrue($new->fixedExpense->is($fixed));
        $fixed->update(['is_active' => false]);
        $page->set('month', '2026-11')->call('openMonth')->assertSet('total', '0.00');
        $page->set('month', '2026-09')->call('openMonth')->assertSee('Old rent');
        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    #[TestWith(['2026-01', '2026-01-31'])]
    #[TestWith(['2026-04', '2026-04-30'])]
    #[TestWith(['2026-02', '2026-02-28'])]
    #[TestWith(['2028-02', '2028-02-29'])]
    public function test_generated_occurrences_reuse_the_short_month_rule(string $month, string $date): void
    {
        $this->travelTo(now()->setDate(2020, 1, 1));
        $fixed = FixedExpense::factory()->create(['day_of_month' => 31, 'start_date' => '2026-01-01']);
        $this->actingAs($fixed->user);
        Livewire::test('pages::expenses.index')->set('month', $month)->call('openMonth')->assertSee($date);
        $this->assertSame($date, $fixed->expenses()->sole()->expense_date->toDateString());
    }

    public function test_start_date_and_inactive_category_rules_apply_to_generation(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $fixed = FixedExpense::factory()->create(['day_of_month' => 10, 'start_date' => '2026-09-20']);
        $fixed->expenseCategory->update(['is_active' => false]);
        $monthEnd = FixedExpense::factory()->for($fixed->user)->create(['day_of_month' => 31, 'start_date' => '2026-09-30']);
        $this->actingAs($fixed->user);

        $page = Livewire::test('pages::expenses.index');
        $this->assertSame(0, $fixed->expenses()->count());
        $this->assertSame('2026-09-30', $monthEnd->expenses()->sole()->expense_date->toDateString());
        $page->set('month', '2026-10')->call('openMonth');
        $this->assertSame($fixed->expense_category_id, $fixed->expenses()->sole()->expense_category_id);
        $this->assertFalse($fixed->expenseCategory->refresh()->is_active);
    }

    public function test_recurring_edit_changes_only_snapshot_and_stays_in_occurrence_month(): void
    {
        $fixed = FixedExpense::factory()->create(['day_of_month' => 10]);
        $this->actingAs($fixed->user);
        app(MaterializeFixedExpensesForMonth::class)->handle($fixed->user, 2026, 9);
        $expense = $fixed->expenses()->sole();
        $template = $fixed->refresh()->getRawOriginal();
        $category = ExpenseCategory::factory()->for($fixed->user)->create();

        $page = Livewire::test('pages::expenses.form', ['expenseId' => $expense->id]);
        $page->set('expense_date', '2026-10-01')->call('save')->assertHasErrors('expense_date');
        $this->assertSame('2026-09-10', $expense->refresh()->expense_date->toDateString());
        $page->set(['name' => 'Actual rent', 'amount' => '150.25', 'expense_category_id' => $category->id,
            'expense_date' => '2026-09-15', 'description' => ' Negotiated '])->call('save')->assertHasNoErrors();

        $this->assertSame('Actual rent', $expense->refresh()->name);
        $this->assertSame('150.25', $expense->amount);
        $this->assertSame('Negotiated', $expense->description);
        $this->assertSame($category->id, $expense->expense_category_id);
        $this->assertSame('2026-09-15', $expense->expense_date->toDateString());
        $this->assertSame(2026, $expense->occurrence_year);
        $this->assertSame(9, $expense->occurrence_month);
        $this->assertSame($fixed->id, $expense->fixed_expense_id);
        $this->assertSame($template, $fixed->refresh()->getRawOriginal());
    }

    public function test_deleted_recurring_expenses_block_regeneration_and_restore_the_same_row(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $fixed = FixedExpense::factory()->create(['name' => 'Recurring bill']);
        $this->actingAs($fixed->user);
        $page = Livewire::test('pages::expenses.index');
        $expense = $fixed->expenses()->sole();

        $page->call('deleteExpense', $expense->id)->assertDontSee('Recurring bill')->assertSet('deletedCount', 1)->assertSet('total', '0.00');
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
        $page->call('openMonth')->assertDontSee('Recurring bill')->set('showDeleted', true)->assertSee('Recurring bill');
        $this->assertSame($expense->id, $fixed->expenses()->sole()->id);
        $page->call('restoreExpense', $expense->id)->assertSet('deletedCount', 0)->assertSee('Recurring bill');
        $this->assertNull($expense->refresh()->deleted_by_user_at);
        $this->assertDatabaseCount('expenses', 1);
        $this->delete('/expenses/'.$expense->id)->assertNotFound();
        $this->assertModelExists($expense);
    }

    public function test_manual_deletion_month_filter_and_exact_total(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $user = User::factory()->create();
        $expense = Expense::factory()->for($user)->create(['amount' => '0.10']);
        Expense::factory()->for($user)->create(['amount' => '0.20']);
        Expense::factory()->for($user)->deleted()->create(['name' => 'October deleted', 'expense_date' => '2026-10-01']);
        $this->actingAs($user);

        Livewire::test('pages::expenses.index')->assertSet('total', '0.30')->assertSet('deletedCount', 0)
            ->call('deleteExpense', $expense->id)->assertSet('total', '0.20')->assertSet('deletedCount', 1)
            ->set('month', '2026-10')->call('openMonth')->set('showDeleted', true)->assertSee('October deleted')->assertSet('deletedCount', 1);
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
        $this->assertDatabaseCount('expenses', 3);
        $this->get(route('expenses.edit', $expense))->assertNotFound();
    }

    #[TestWith(['restoreDuplicate', 1, false])]
    #[TestWith(['createAnyway', 2, true])]
    #[TestWith(['cancelDuplicate', 1, true])]
    public function test_deleted_manual_duplicate_requires_an_explicit_choice(string $choice, int $count, bool $stillDeleted): void
    {
        $expense = Expense::factory()->deleted()->create(['name' => 'Lunch', 'description' => null]);
        $this->actingAs($expense->user);
        $page = Livewire::test('pages::expenses.form')->set($this->fields($expense->expenseCategory, ['name' => ' Lunch ', 'amount' => '100.0', 'description' => '   ']))
            ->call('save')->assertSet('showDuplicate', true)->assertSet('duplicateId', $expense->id)->assertSee('A matching deleted expense already exists.');
        $this->assertDatabaseCount('expenses', 1);
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);

        $page->call($choice)->assertHasNoErrors();

        $this->assertDatabaseCount('expenses', $count);
        $this->assertSame($stillDeleted, $expense->refresh()->deleted_by_user_at !== null);
    }

    #[TestWith(['amount', '100.01'])]
    #[TestWith(['expense_date', '2026-09-11'])]
    #[TestWith(['name', 'lunch'])]
    #[TestWith(['description', '0'])]
    public function test_nonidentical_manual_expenses_do_not_warn(string $field, string $value): void
    {
        $expense = Expense::factory()->deleted()->create(['name' => 'Lunch']);
        $this->actingAs($expense->user);
        Livewire::test('pages::expenses.form')->set($this->fields($expense->expenseCategory, [$field => $value]))->call('save')
            ->assertSet('showDuplicate', false)->assertHasNoErrors();
        $this->assertDatabaseCount('expenses', 2);
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
    }

    public function test_different_categories_and_foreign_deleted_matches_do_not_warn(): void
    {
        $expense = Expense::factory()->deleted()->create(['name' => 'Lunch']);
        $category = ExpenseCategory::factory()->for($expense->user)->create();
        Expense::factory()->deleted()->create(['name' => 'Lunch', 'expense_category_id' => $category->id]);
        $this->actingAs($expense->user);
        Livewire::test('pages::expenses.form')->set($this->fields($category))->call('save')->assertSet('showDuplicate', false);
        $this->assertDatabaseCount('expenses', 3);
    }

    #[TestWith(['restoreDuplicate'])]
    #[TestWith(['createAnyway'])]
    public function test_duplicate_final_actions_revalidate_and_recheck_the_candidate(string $action): void
    {
        $expense = Expense::factory()->deleted()->create(['name' => 'Lunch']);
        $this->actingAs($expense->user);
        $page = Livewire::test('pages::expenses.form')->set($this->fields($expense->expenseCategory))->call('save');
        $page->set('amount', '0')->call($action)->assertHasErrors('amount');
        $page->set('amount', '200')->call($action)->assertHasErrors('name')->assertSet('duplicateId', null);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
    }

    public function test_stale_duplicate_candidate_cannot_be_restored_twice(): void
    {
        $expense = Expense::factory()->deleted()->create(['name' => 'Lunch']);
        $this->actingAs($expense->user);
        $page = Livewire::test('pages::expenses.form')->set($this->fields($expense->expenseCategory))->call('save');
        $expense->deleted_by_user_at = null;
        $expense->save();
        $page->call('restoreDuplicate')->assertNotFound();
        $this->assertDatabaseCount('expenses', 1);
    }

    #[TestWith(['2026-00'])]
    #[TestWith(['2026-13'])]
    #[TestWith(['0999-12'])]
    #[TestWith(['10000-01'])]
    #[TestWith(['invalid'])]
    public function test_invalid_month_does_not_materialize(string $month): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $fixed = FixedExpense::factory()->create(['start_date' => '2026-10-01']);
        $this->actingAs($fixed->user);
        Livewire::test('pages::expenses.index')->set('month', $month)->call('openMonth')->assertHasErrors('month')->assertSet('selectedMonth', '2026-09');
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_opened_month_is_locked(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::expenses.index');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('selectedMonth', '2026-13');
    }

    public function test_recurring_unique_identity_includes_deleted_rows(): void
    {
        $fixed = FixedExpense::factory()->create();
        app(MaterializeFixedExpensesForMonth::class)->handle($fixed->user, 2026, 9);
        $expense = $fixed->expenses()->sole();
        $expense->deleted_by_user_at = now();
        $expense->save();
        $this->expectException(UniqueConstraintViolationException::class);
        Expense::factory()->for($fixed->user)->create(['fixed_expense_id' => $fixed->id, 'occurrence_year' => 2026, 'occurrence_month' => 9]);
    }

    public function test_competing_materialization_insert_is_reused(): void
    {
        $fixed = FixedExpense::factory()->create();
        $inserted = false;
        DB::listen(function ($query) use ($fixed, &$inserted): void {
            if (! $inserted && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"expenses"')) {
                $inserted = true;
                Expense::factory()->for($fixed->user)->create(['fixed_expense_id' => $fixed->id,
                    'occurrence_year' => 2026, 'occurrence_month' => 9, 'amount' => '123.45']);
            }
        });

        app(MaterializeFixedExpensesForMonth::class)->handle($fixed->user, 2026, 9);

        $this->assertSame('123.45', $fixed->expenses()->sole()->amount);
    }

    public function test_account_deletion_removes_only_the_authenticated_users_expense_history(): void
    {
        $fixed = FixedExpense::factory()->create();
        app(MaterializeFixedExpensesForMonth::class)->handle($fixed->user, 2026, 9);
        $expense = Expense::factory()->for($fixed->user)->deleted()->create();
        $foreign = Expense::factory()->create();
        $this->actingAs($fixed->user);

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')
            ->assertHasNoErrors()->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $fixed->user_id]);
        $this->assertDatabaseMissing('expenses', ['user_id' => $fixed->user_id]);
        $this->assertModelExists($foreign);
        $this->assertGuest();
    }

    public function test_wrong_password_does_not_remove_expense_history(): void
    {
        $expense = Expense::factory()->create();
        $this->actingAs($expense->user);
        Livewire::test('pages::settings.delete-user-modal')->set('password', 'wrong')->call('deleteUser')->assertHasErrors('password');
        $this->assertModelExists($expense);
        $this->assertAuthenticatedAs($expense->user);
    }

    public function test_edit_does_not_warn_about_deleted_manual_duplicates(): void
    {
        $expense = Expense::factory()->create(['name' => 'Lunch']);
        Expense::factory()->for($expense->user)->for($expense->expenseCategory)->deleted()->create(['name' => 'Lunch']);
        $this->actingAs($expense->user);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->call('save')->assertHasNoErrors()->assertSet('showDuplicate', false);

        $this->assertDatabaseCount('expenses', 2);
    }

    public function test_deleted_recurring_match_does_not_trigger_manual_duplicate_warning(): void
    {
        $fixed = FixedExpense::factory()->create(['name' => 'Lunch', 'amount' => '100.00', 'day_of_month' => 10]);
        app(MaterializeFixedExpensesForMonth::class)->handle($fixed->user, 2026, 9);
        $expense = $fixed->expenses()->sole();
        $expense->deleted_by_user_at = now();
        $expense->save();
        $this->actingAs($fixed->user);

        Livewire::test('pages::expenses.form')->set($this->fields($fixed->expenseCategory))->call('save')->assertHasNoErrors()->assertSet('showDuplicate', false);

        $this->assertSame(2, $fixed->user->expenses()->count());
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
    }

    #[TestWith(['restoreDuplicate'])]
    #[TestWith(['createAnyway'])]
    public function test_duplicate_final_action_refetches_through_owner(string $action): void
    {
        $expense = Expense::factory()->deleted()->create(['name' => 'Lunch']);
        $owner = $expense->user;
        $other = User::factory()->create();
        $this->actingAs($owner);
        $page = Livewire::test('pages::expenses.form')->set($this->fields($expense->expenseCategory))->call('save');
        $expense->user_id = $other->id;
        $expense->save();

        $page->call($action)->assertNotFound();

        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_duplicate_match_uses_most_recently_deleted_exact_normalized_description(): void
    {
        $expense = Expense::factory()->deleted()->create(['name' => 'Lunch', 'description' => ' Note ', 'deleted_by_user_at' => '2026-09-01']);
        $recent = Expense::factory()->for($expense->user)->for($expense->expenseCategory)->deleted()
            ->create(['name' => 'Lunch', 'description' => 'Note', 'deleted_by_user_at' => '2026-09-02']);
        $this->actingAs($expense->user);

        Livewire::test('pages::expenses.form')->set($this->fields($expense->expenseCategory, ['description' => ' Note ']))
            ->call('save')->assertSet('duplicateId', $recent->id)->call('restoreDuplicate')->assertHasNoErrors();

        $this->assertNull($recent->refresh()->deleted_by_user_at);
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
        $this->assertDatabaseCount('expenses', 2);
    }

    public function test_rendered_names_are_escaped_and_foreign_category_names_are_hidden(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $expense = Expense::factory()->create(['name' => '<script>alert(1)</script>']);
        $category = ExpenseCategory::factory()->create(['name' => 'Private category']);
        $expense->expense_category_id = $category->id;
        $expense->save();
        $this->actingAs($expense->user);

        Livewire::test('pages::expenses.index')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Private category')->assertSee('Category unavailable');
    }

    public function test_month_query_opens_only_that_month_and_links_preserve_the_opened_month(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30));
        $fixed = FixedExpense::factory()->create(['start_date' => '2026-01-01']);
        $this->actingAs($fixed->user);

        $this->get('/expenses?month=2026-08')->assertSee('August 2026')
            ->assertSee(route('expenses.create', ['month' => '2026-08']));
        $expense = $fixed->expenses()->sole();
        $this->assertSame(8, $expense->occurrence_month);
        Livewire::withQueryParams(['month' => '2026-08'])->test('pages::expenses.index')
            ->assertSet('selectedMonth', '2026-08')
            ->assertSee(route('expenses.edit', ['expenseId' => $expense->id, 'month' => '2026-08']))
            ->call('openMonth')->set('month', '2026-07')->call('openMonth')
            ->assertSee(route('expenses.create', ['month' => '2026-07']));
        $this->assertSame(2, $fixed->expenses()->count());
    }

    #[TestWith(['2026-08', '2026-08-30'])]
    #[TestWith(['2026-02', '2026-02-28'])]
    #[TestWith(['2028-02', '2028-02-29'])]
    public function test_create_defaults_inside_selected_month_and_returns_to_it(string $month, string $date): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30));
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        Livewire::withQueryParams(['month' => $month])->test('pages::expenses.form')
            ->assertSet('expense_date', $date)->assertSee(route('expenses.index', ['month' => $month]))
            ->set(['name' => 'Lunch', 'amount' => '10.00', 'expense_category_id' => $category->id])
            ->call('save')->assertHasNoErrors()->assertRedirect(route('expenses.index', ['month' => $month]));
        $this->assertSame($date, $category->user->expenses()->sole()->expense_date->toDateString());
    }

    public function test_edit_keeps_actual_date_and_back_and_save_return_to_originating_month(): void
    {
        $expense = Expense::factory()->create(['expense_date' => '2026-08-12']);
        $this->actingAs($expense->user);
        Livewire::withQueryParams(['month' => '2026-08'])->test('pages::expenses.form', ['expenseId' => $expense->id])
            ->assertSet('expense_date', '2026-08-12')->assertSee(route('expenses.index', ['month' => '2026-08']))
            ->set('name', 'Updated')->call('save')->assertHasNoErrors()->assertRedirect(route('expenses.index', ['month' => '2026-08']));
        $this->assertSame('Updated', $expense->refresh()->name);
    }

    #[TestWith(['restoreDuplicate'])]
    #[TestWith(['createAnyway'])]
    public function test_duplicate_choices_return_to_originating_month(string $action): void
    {
        $expense = Expense::factory()->deleted()->create(['name' => 'Lunch', 'expense_date' => '2026-08-10']);
        $this->actingAs($expense->user);
        Livewire::withQueryParams(['month' => '2026-08'])->test('pages::expenses.form')
            ->set($this->fields($expense->expenseCategory, ['expense_date' => '2026-08-10']))->call('save')
            ->assertSet('duplicateId', $expense->id)->call($action)->assertHasNoErrors()
            ->assertRedirect(route('expenses.index', ['month' => '2026-08']));
    }

    #[TestWith(['invalid'])]
    #[TestWith(['2026-13'])]
    #[TestWith(['0999-12'])]
    #[TestWith(['10000-01'])]
    #[TestWith([['2026-08']])]
    public function test_malformed_month_query_falls_back_to_current_month(mixed $month): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30));
        $this->actingAs(User::factory()->create());
        Livewire::withQueryParams(['month' => $month])->test('pages::expenses.index')->assertSet('selectedMonth', '2026-09');
        Livewire::withQueryParams(['month' => $month])->test('pages::expenses.form')->assertSet('returnMonth', null)->assertSet('expense_date', '2026-09-30');
    }

    public function test_originating_month_cannot_be_tampered_with(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::withQueryParams(['month' => '2026-08'])->test('pages::expenses.form');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('returnMonth', '2026-13');
    }

    #[TestWith([false, 'heart', '#ABCDEF', '#ABCDEF'])]
    #[TestWith([true, 'heart', '#ABCDEF', '#ABCDEF'])]
    #[TestWith([false, '../bad-icon', 'invalid', '#64748B'])]
    #[TestWith([true, '../bad-icon', 'invalid', '#64748B'])]
    public function test_category_icons_render_safely_in_normal_and_deleted_lists(bool $deleted, string $icon, string $color, string $expectedColor): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7));
        $category = ExpenseCategory::factory()->create(['name' => 'Health', 'icon' => $icon, 'color' => $color]);
        Expense::factory()->for($category->user)->for($category)->create(['deleted_by_user_at' => $deleted ? now() : null]);
        $this->actingAs($category->user);

        $page = Livewire::test('pages::expenses.index')->set('showDeleted', $deleted)
            ->assertSee('Health')->assertSee('color: '.$expectedColor, false)->assertDontSee('../bad-icon');

        $document = new \DOMDocument;
        @$document->loadHTML($page->html());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//tbody/tr/td[2]//svg[@data-flux-icon]')->length);
    }

    public function test_expense_grid_filters_inside_the_selected_month_and_clear_filters_preserves_month(): void
    {
        $user = User::factory()->create(['currency' => 'USD']);
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        $otherCategory = ExpenseCategory::factory()->for($user)->inactive()->create(['name' => 'Archive']);
        $account = Account::factory()->for($user)->create(['name' => 'Cash']);
        Expense::factory()->for($user)->for($category)->create([
            'name' => 'September lunch', 'description' => 'Office meal', 'amount' => '10.00',
            'expense_date' => '2026-09-10', 'payment_account_id' => $account->id,
        ]);
        Expense::factory()->for($user)->for($otherCategory)->create([
            'name' => 'August lunch', 'description' => 'Old meal', 'expense_date' => '2026-08-10',
        ]);
        $this->actingAs($user);

        $page = Livewire::withQueryParams([
            'month' => '2026-09', 'search' => 'Office', 'category' => $category->id,
            'source' => 'manual', 'payment_source' => 'account:'.$account->id,
        ])->test('pages::expenses.index')
            ->assertSet('selectedMonth', '2026-09')
            ->assertSee('September lunch')
            ->assertDontSee('August lunch')
            ->assertSee('USD 10.00');

        $page->call('clearFilters')
            ->assertSet('selectedMonth', '2026-09')
            ->assertSet('search', '')
            ->assertSet('category', '')
            ->assertSet('source', '')
            ->assertSet('paymentSource', '')
            ->assertSee('September lunch')
            ->assertDontSee('August lunch');
    }

    public function test_expense_grid_supports_source_payment_search_and_sort_filters(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => 'Food']);
        $account = Account::factory()->for($user)->create(['name' => 'Itaú']);
        $card = CreditCard::factory()->for($user)->create(['name' => 'Nubank']);
        $fixed = FixedExpense::factory()->for($user)->for($category)->create();
        Expense::factory()->for($user)->for($category)->create([
            'name' => 'Manual cash', 'amount' => '20.00', 'expense_date' => '2026-09-11',
        ]);
        Expense::factory()->for($user)->for($category)->create([
            'name' => 'Manual account', 'amount' => '50.00', 'expense_date' => '2026-09-12',
            'payment_account_id' => $account->id, 'description' => 'Account search',
        ]);
        Expense::factory()->for($user)->for($category)->create([
            'name' => 'Recurring card', 'amount' => '90.00', 'expense_date' => '2026-09-13',
            'fixed_expense_id' => $fixed->id, 'occurrence_year' => 2026, 'occurrence_month' => 9,
            'credit_card_id' => $card->id,
        ]);
        $this->actingAs($user);

        Livewire::withQueryParams(['month' => '2026-09', 'source' => 'recurring', 'payment_source' => 'card:'.$card->id, 'sort' => 'amount', 'direction' => 'desc'])
            ->test('pages::expenses.index')
            ->assertSee('Recurring card')->assertDontSee('Manual account')->assertDontSee('Manual cash');
        Livewire::withQueryParams(['month' => '2026-09', 'payment_source' => 'none'])
            ->test('pages::expenses.index')->assertSee('Manual cash')->assertDontSee('Manual account')->assertDontSee('Recurring card');
    }

    public function test_expense_grid_rejects_stale_foreign_filters_without_exception_or_data_leak(): void
    {
        $user = User::factory()->create();
        $own = Expense::factory()->for($user)->create(['name' => 'Own expense']);
        $foreign = Expense::factory()->create(['name' => 'Foreign expense']);
        $foreignCategory = $foreign->expenseCategory;
        $this->actingAs($user);

        Livewire::withQueryParams([
            'month' => '2026-09', 'category' => $foreignCategory->id,
            'source' => 'invalid', 'payment_source' => 'account:999999',
            'sort' => 'hacked_column', 'direction' => 'hacked_direction',
        ])->test('pages::expenses.index')
            ->assertSet('category', '')
            ->assertSet('source', '')
            ->assertSet('paymentSource', '')
            ->assertSet('sort', 'date')
            ->assertSet('direction', 'asc')
            ->assertSee('Own expense')
            ->assertDontSee('Foreign expense');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fields(ExpenseCategory $category, array $overrides = []): array
    {
        return array_replace(['name' => 'Lunch', 'expense_category_id' => $category->id, 'amount' => '100.00',
            'expense_date' => '2026-09-10', 'description' => null], $overrides);
    }
}
