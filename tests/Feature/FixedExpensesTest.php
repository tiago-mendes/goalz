<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FixedExpensesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_fixed_expense_pages(): void
    {
        $expense = FixedExpense::factory()->create();
        foreach (['fixed-expenses.index', 'fixed-expenses.create', 'fixed-expenses.edit'] as $route) {
            $this->get(route($route, $route === 'fixed-expenses.edit' ? $expense->id : []))->assertRedirect(route('login'));
        }
        Livewire::test('pages::fixed-expenses.index')->assertForbidden();
        Livewire::test('pages::fixed-expenses.form')->assertForbidden();
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_users_only_see_and_manage_their_own_definitions(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'currency' => 'USD']);
        $own = FixedExpense::factory()->for($user)->inactive()->create(['name' => 'My rent', 'amount' => '2500.50']);
        $foreign = FixedExpense::factory()->create(['name' => 'Private rent', 'amount' => '876543.21']);
        $this->actingAs($user);

        $this->get(route('fixed-expenses.index'))->assertOk()->assertSeeText('My rent')->assertSeeText('Inactive')
            ->assertSeeText('USD 2500.50')->assertSeeText($own->expenseCategory->name)
            ->assertDontSeeText('Private rent')->assertDontSee('876543.21')->assertDontSeeText($foreign->expenseCategory->name);
        $this->get(route('fixed-expenses.create'))->assertOk();
        $this->get(route('fixed-expenses.edit', $own->id))->assertOk();
        $this->get(route('dashboard'))->assertSee('href="'.route('fixed-expenses.index').'"', false);
        foreach ([$foreign->id, 999999] as $id) {
            $this->get(route('fixed-expenses.edit', $id))->assertNotFound();
            Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $id])->assertNotFound();
            Livewire::test('pages::fixed-expenses.index')->call('setActive', $id, false)->assertNotFound();
        }
        $this->assertTrue($foreign->refresh()->is_active);
        $this->assertSame('876543.21', $foreign->amount);
        foreach (['view', 'update'] as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $own));
            $this->assertSame(404, Gate::forUser($user)->inspect($ability, $foreign)->status());
        }
        $this->assertTrue(Gate::forUser($user)->allows('create', FixedExpense::class));
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', FixedExpense::class));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $own));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $foreign));
    }

    public function test_create_assigns_authenticated_owner_and_all_definition_fields(): void
    {
        $category = ExpenseCategory::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($category->user);

        Livewire::test('pages::fixed-expenses.form')->set($this->fields($category))
            ->call('save', ['user_id' => $other->id])->assertHasNoErrors()->assertRedirect(route('fixed-expenses.index'));

        $expense = $category->user->fixedExpenses()->sole();
        $this->assertSame('Rent', $expense->name);
        $this->assertSame('2500.50', $expense->amount);
        $this->assertSame(10, $expense->day_of_month);
        $this->assertSame('2026-09-01', $expense->start_date->toDateString());
        $this->assertTrue($expense->is_active);
        $this->assertTrue($expense->user->is($category->user));
        $this->assertTrue($expense->expenseCategory->is($category));
        $this->assertTrue($category->fixedExpenses()->sole()->is($expense));
        $expense->fill(['user_id' => $other->id])->save();
        $this->assertSame($category->user_id, $expense->refresh()->user_id);
        $this->assertSame(0, $other->fixedExpenses()->count());
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    public function test_owner_can_edit_every_definition_field_and_switch_to_an_active_category(): void
    {
        $expense = FixedExpense::factory()->create();
        $category = ExpenseCategory::factory()->for($expense->user)->create();
        $this->actingAs($expense->user);

        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $expense->id])->set([
            'name' => '  Internet  ', 'expense_category_id' => $category->id, 'amount' => '150',
            'day_of_month' => 31, 'start_date' => '2027-01-01', 'is_active' => false,
        ])->call('save')->assertHasNoErrors()->assertRedirect(route('fixed-expenses.index'));

        $expense->refresh();
        $this->assertSame('Internet', $expense->name);
        $this->assertSame($category->id, $expense->expense_category_id);
        $this->assertSame('150.00', $expense->amount);
        $this->assertSame(31, $expense->day_of_month);
        $this->assertSame('2027-01-01', $expense->start_date->toDateString());
        $this->assertFalse($expense->is_active);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    #[TestWith([true, false])]
    #[TestWith([false, true])]
    public function test_activation_only_changes_active_status(bool $before, bool $after): void
    {
        $this->freezeSecond();
        $expense = FixedExpense::factory()->create(['is_active' => $before]);
        $original = $expense->refresh()->getRawOriginal();
        $this->actingAs($expense->user);
        Livewire::test('pages::fixed-expenses.index')->call('setActive', $expense->id, $after)->assertHasNoErrors();
        $this->assertSame($after, $expense->refresh()->is_active);
        foreach (['name', 'amount', 'day_of_month', 'start_date', 'expense_category_id', 'user_id'] as $field) {
            $this->assertSame($original[$field], $expense->getRawOriginal($field));
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_foreign_and_nonexistent_categories_are_rejected_identically(string $role): void
    {
        $own = ExpenseCategory::factory()->create();
        $own->user->forceFill(['role' => $role])->save();
        $foreign = ExpenseCategory::factory()->create(['name' => 'Private category']);
        $this->actingAs($own->user);
        foreach ([$foreign->id, 999999] as $id) {
            Livewire::test('pages::fixed-expenses.form')->assertDontSee('Private category')
                ->set($this->fields($own))->set('expense_category_id', $id)->call('save')
                ->assertHasErrors(['expense_category_id' => 'exists'])
                ->assertSee('Choose one of your active categories');
        }
        $this->assertDatabaseCount('fixed_expenses', 0);
    }

    public function test_new_fixed_expense_requires_an_active_category_and_offers_category_management(): void
    {
        $category = ExpenseCategory::factory()->inactive()->create();
        $this->actingAs($category->user);
        Livewire::test('pages::fixed-expenses.form')->assertSee('An active category is required')
            ->assertSee('href="'.route('expense-categories.index').'"', false)
            ->set($this->fields($category))->call('save')->assertHasErrors(['expense_category_id' => 'exists']);
        $this->assertDatabaseCount('fixed_expenses', 0);
        $this->assertFalse($category->refresh()->is_active);
        $this->assertSame(1, $category->user->expenseCategories()->count());
    }

    public function test_current_category_can_be_retained_after_it_becomes_inactive(): void
    {
        $expense = FixedExpense::factory()->create();
        $category = $expense->expenseCategory;
        $category->is_active = false;
        $category->save();
        $this->actingAs($expense->user);
        $this->get(route('fixed-expenses.edit', $expense->id))->assertOk()->assertSeeText($category->name);
        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $expense->id])
            ->assertSee('Inactive')->set('name', 'Updated rent')->call('save')->assertHasNoErrors();
        $this->assertSame('Updated rent', $expense->refresh()->name);
        $this->assertSame($category->id, $expense->expense_category_id);
        $this->assertFalse($category->refresh()->is_active);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_replacement_category_must_be_active_and_owned(bool $foreign): void
    {
        $expense = FixedExpense::factory()->create();
        $replacement = $foreign ? ExpenseCategory::factory()->create() : ExpenseCategory::factory()->for($expense->user)->inactive()->create();
        $originalCategoryId = $expense->expense_category_id;
        $this->actingAs($expense->user);
        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $expense->id])
            ->set('expense_category_id', $replacement->id)->call('save')->assertHasErrors(['expense_category_id' => 'exists']);
        $this->assertSame($originalCategoryId, $expense->refresh()->expense_category_id);
    }

    public function test_category_deactivated_after_form_load_is_rejected_on_create(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        $page = Livewire::test('pages::fixed-expenses.form')->set($this->fields($category));
        $category->update(['is_active' => false]);
        $page->call('save')->assertHasErrors(['expense_category_id' => 'exists']);
        $this->assertDatabaseCount('fixed_expenses', 0);
    }

    #[TestWith(['0.01', '0.01', 1])]
    #[TestWith(['100', '100.00', 31])]
    #[TestWith(['1000.50', '1000.50', 10])]
    public function test_valid_amounts_and_boundary_days_are_preserved(string $input, string $expected, int $day): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        Livewire::test('pages::fixed-expenses.form')->set($this->fields($category))->set('amount', $input)
            ->set('day_of_month', $day)->call('save')->assertHasNoErrors();
        $expense = $category->user->fixedExpenses()->sole();
        $this->assertSame($expected, $expense->amount);
        $this->assertSame($day, $expense->day_of_month);
    }

    public function test_maximum_amount_is_sent_to_database_as_an_exact_string(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        $bindings = [];
        DB::listen(function ($query) use (&$bindings): void {
            if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'fixed_expenses')) {
                $bindings = $query->bindings;
            }
        });
        Livewire::test('pages::fixed-expenses.form')->set($this->fields($category))->set('amount', '9999999999999.99')
            ->call('save')->assertHasNoErrors();
        $this->assertContains('9999999999999.99', $bindings);
        $this->assertSame('9999999999999.99', FixedExpense::factory()->make(['amount' => '9999999999999.99'])->amount);
    }

    #[TestWith(['amount', '', 'required'])]
    #[TestWith(['day_of_month', '', 'required'])]
    #[TestWith(['amount', '0', 'not_in'])]
    #[TestWith(['amount', '0.00', 'not_in'])]
    #[TestWith(['amount', '0.0', 'not_in'])]
    #[TestWith(['amount', '-1', 'regex'])]
    #[TestWith(['amount', '1e3', 'regex'])]
    #[TestWith(['amount', '0.001', 'regex'])]
    #[TestWith(['amount', '10000000000000.00', 'regex'])]
    #[TestWith(['amount', 'NaN', 'regex'])]
    #[TestWith(['amount', 'Infinity', 'regex'])]
    #[TestWith(['day_of_month', 0, 'between'])]
    #[TestWith(['day_of_month', 32, 'between'])]
    #[TestWith(['day_of_month', '1.5', 'integer'])]
    #[TestWith(['start_date', '', 'required'])]
    #[TestWith(['start_date', '2026-02-30', 'date_format'])]
    #[TestWith(['start_date', 'not-a-date', 'date_format'])]
    #[TestWith(['start_date', '0999-01-01', 'after_or_equal'])]
    #[TestWith(['name', '   ', 'required'])]
    #[TestWith(['is_active', 'invalid', 'boolean'])]
    #[TestWith(['expense_category_id', '', 'required'])]
    #[TestWith(['expense_category_id', 'invalid', 'integer'])]
    public function test_invalid_fields_are_rejected_without_writes(string $field, mixed $value, string $rule): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        Livewire::test('pages::fixed-expenses.form')->set($this->fields($category))->set($field, $value)
            ->call('save')->assertHasErrors([$field => $rule]);
        $this->assertDatabaseCount('fixed_expenses', 0);
    }

    public function test_name_length_is_limited_to_one_hundred_characters(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        Livewire::test('pages::fixed-expenses.form')->set($this->fields($category))->set('name', str_repeat('a', 101))
            ->call('save')->assertHasErrors(['name' => 'max']);
        $this->assertDatabaseCount('fixed_expenses', 0);
    }

    public function test_duplicate_names_are_allowed_for_one_owner(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        foreach ([1, 2] as $unused) {
            Livewire::test('pages::fixed-expenses.form')->set($this->fields($category))->call('save')->assertHasNoErrors();
        }
        $this->assertSame(2, $category->user->fixedExpenses()->where('name', 'Rent')->count());
    }

    public function test_malformed_category_state_is_rejected_on_edit(): void
    {
        $expense = FixedExpense::factory()->create();
        $this->actingAs($expense->user);
        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $expense->id])
            ->set('expense_category_id', [$expense->expense_category_id])->call('save')
            ->assertHasErrors(['expense_category_id' => 'integer']);
        $this->assertSame($expense->expense_category_id, $expense->fresh()->expense_category_id);
    }

    public function test_fixed_expense_id_cannot_be_manipulated(): void
    {
        $expense = FixedExpense::factory()->create();
        $foreign = FixedExpense::factory()->create();
        $this->actingAs($expense->user);
        $page = Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $expense->id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('fixedExpenseId', $foreign->id);
    }

    public function test_user_id_state_cannot_be_injected(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::fixed-expenses.form');
        $this->expectException(PublicPropertyNotFoundException::class);
        $page->set('user_id', 999999);
    }

    public function test_save_rechecks_ownership_after_mount(): void
    {
        $expense = FixedExpense::factory()->create(['name' => 'Original']);
        $other = User::factory()->create();
        $this->actingAs($expense->user);
        $page = Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $expense->id])->set('name', 'Changed');
        $expense->user()->associate($other)->save();
        $page->call('save')->assertNotFound();
        $this->assertSame('Original', $expense->refresh()->name);
    }

    public function test_no_delete_action_is_exposed(): void
    {
        $expense = FixedExpense::factory()->create();
        $this->actingAs($expense->user);
        $this->get(route('fixed-expenses.index'))->assertDontSeeText('Delete');
        $this->delete('/fixed-expenses/'.$expense->id)->assertNotFound();
        foreach (['pages::fixed-expenses.index', 'pages::fixed-expenses.form'] as $component) {
            $page = Livewire::test($component);
            $this->assertFalse(method_exists($page->instance(), 'delete'));
        }
        $this->assertModelExists($expense);
    }

    public function test_names_are_escaped_and_list_is_paginated(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create(['name' => '<script>category</script>']);
        FixedExpense::factory()->for($user)->create(['name' => '<script>expense</script>', 'expense_category_id' => $category->id]);
        FixedExpense::factory()->for($user)->count(20)->create(['name' => 'Z last', 'expense_category_id' => $category->id]);
        $this->actingAs($user);
        $page = Livewire::test('pages::fixed-expenses.index')->assertSee('<script>expense</script>')
            ->assertDontSee('<script>expense</script>', false)->assertDontSee('<script>category</script>', false);
        $this->assertCount(20, $page->instance()->fixedExpenses->items());
        $page->call('gotoPage', 2)->assertSee('Z last');
        $this->assertCount(1, $page->instance()->fixedExpenses->items());
    }

    /** @return array{name: string, expense_category_id: int, amount: string, day_of_month: int, start_date: string, is_active: bool} */
    private function fields(ExpenseCategory $category): array
    {
        return ['name' => '  Rent  ', 'expense_category_id' => $category->id, 'amount' => '2500.50', 'day_of_month' => 10, 'start_date' => '2026-09-01', 'is_active' => true];
    }
}
