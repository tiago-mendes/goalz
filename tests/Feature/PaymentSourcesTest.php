<?php

namespace Tests\Feature;

use App\Actions\MaterializeFixedExpensesForMonth;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaymentSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_payment_source_is_optional_and_can_change_between_account_card_and_unspecified(): void
    {
        $category = ExpenseCategory::factory()->create();
        $account = Account::factory()->for($category->user)->create(['name' => 'Checking']);
        $creditCard = CreditCard::factory()->for($category->user)->create(['name' => 'Nubank']);
        $this->actingAs($category->user);

        Livewire::test('pages::expenses.form')->set($this->expenseFields($category))->call('save')->assertHasNoErrors();
        $expense = $category->user->expenses()->sole();
        $this->assertNull($expense->payment_account_id);
        $this->assertNull($expense->credit_card_id);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->set('payment_source', 'account:'.$account->id)->call('save')->assertHasNoErrors();
        $this->assertSame($account->id, $expense->refresh()->payment_account_id);
        $this->assertNull($expense->credit_card_id);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->set('payment_source', 'credit-card:'.$creditCard->id)->call('save')->assertHasNoErrors();
        $this->assertNull($expense->refresh()->payment_account_id);
        $this->assertSame($creditCard->id, $expense->credit_card_id);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->set('payment_source', 'account:'.$account->id)->call('save')->assertHasNoErrors();
        $this->assertSame($account->id, $expense->refresh()->payment_account_id);
        $this->assertNull($expense->credit_card_id);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])->set('payment_source', '')->call('save')->assertHasNoErrors();
        $this->assertNull($expense->refresh()->payment_account_id);
        $this->assertNull($expense->credit_card_id);
        $this->assertSame('100.00', $account->refresh()->current_balance);
    }

    #[TestWith(['account', false])]
    #[TestWith(['account', true])]
    #[TestWith(['credit-card', false])]
    #[TestWith(['credit-card', true])]
    public function test_expense_rejects_foreign_and_inactive_new_payment_sources(string $type, bool $inactive): void
    {
        $category = ExpenseCategory::factory()->create();
        $source = $type === 'account'
            ? Account::factory()->when($inactive, fn ($factory) => $factory->inactive())->create($inactive ? ['user_id' => $category->user_id] : [])
            : CreditCard::factory()->when($inactive, fn ($factory) => $factory->inactive())->create($inactive ? ['user_id' => $category->user_id] : []);
        $this->actingAs($category->user);

        Livewire::test('pages::expenses.form')
            ->assertDontSeeText($source->name)
            ->set($this->expenseFields($category) + ['payment_source' => $type.':'.$source->id])
            ->call('save')
            ->assertHasErrors('payment_source');

        $this->assertSame(0, $category->user->expenses()->count());
    }

    #[TestWith(['account'])]
    #[TestWith(['credit-card'])]
    public function test_expense_preserves_its_existing_inactive_source_on_unrelated_edit(string $type): void
    {
        $expense = Expense::factory()->create();
        $source = $type === 'account'
            ? Account::factory()->for($expense->user)->inactive()->create()
            : CreditCard::factory()->for($expense->user)->inactive()->create();
        $field = $type === 'account' ? 'payment_account_id' : 'credit_card_id';
        $expense->update([$field => $source->id]);
        $this->actingAs($expense->user);

        Livewire::test('pages::expenses.form', ['expenseId' => $expense->id])
            ->assertSeeText($source->name)
            ->assertSeeText('Inactive — current source')
            ->set('name', 'Updated')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($source->id, $expense->refresh()->$field);
    }

    public function test_fixed_expense_supports_the_same_payment_source_rules(): void
    {
        $category = ExpenseCategory::factory()->create();
        $account = Account::factory()->for($category->user)->create();
        $card = CreditCard::factory()->for($category->user)->create();
        $foreign = CreditCard::factory()->create(['name' => 'Foreign card']);
        $inactive = Account::factory()->for($category->user)->inactive()->create(['name' => 'Old account']);
        $this->actingAs($category->user);

        Livewire::test('pages::fixed-expenses.form')
            ->set($this->fixedExpenseFields($category) + ['payment_source' => 'account:'.$account->id])
            ->call('save')->assertHasNoErrors();
        $fixedExpense = $category->user->fixedExpenses()->sole();
        $this->assertSame($account->id, $fixedExpense->payment_account_id);
        $this->assertNull($fixedExpense->credit_card_id);

        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $fixedExpense->id])
            ->set('payment_source', 'credit-card:'.$card->id)->call('save')->assertHasNoErrors();
        $this->assertNull($fixedExpense->refresh()->payment_account_id);
        $this->assertSame($card->id, $fixedExpense->credit_card_id);

        foreach (['credit-card:'.$foreign->id, 'account:'.$inactive->id] as $selection) {
            Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $fixedExpense->id])
                ->set('payment_source', $selection)->call('save')->assertHasErrors('payment_source');
        }

        $card->update(['is_active' => false]);
        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $fixedExpense->id])
            ->set('name', 'Updated recurring cost')->call('save')->assertHasNoErrors();
        $this->assertSame($card->id, $fixedExpense->refresh()->credit_card_id);

        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $fixedExpense->id])
            ->set('payment_source', 'account:'.$account->id)->call('save')->assertHasNoErrors();
        $this->assertSame($account->id, $fixedExpense->refresh()->payment_account_id);
        $this->assertNull($fixedExpense->credit_card_id);

        Livewire::test('pages::fixed-expenses.form', ['fixedExpenseId' => $fixedExpense->id])
            ->set('payment_source', '')->call('save')->assertHasNoErrors();
        $this->assertNull($fixedExpense->refresh()->payment_account_id);
        $this->assertNull($fixedExpense->credit_card_id);
    }

    public function test_materialization_snapshots_the_configured_payment_source_without_retroactive_updates(): void
    {
        $fixedExpense = FixedExpense::factory()->create(['day_of_month' => 10]);
        $card = CreditCard::factory()->for($fixedExpense->user)->create();
        $account = Account::factory()->for($fixedExpense->user)->create();
        $fixedExpense->update(['credit_card_id' => $card->id]);
        $action = app(MaterializeFixedExpensesForMonth::class);

        $action->handle($fixedExpense->user, 2026, 9);
        $september = $fixedExpense->expenses()->sole();
        $this->assertSame($card->id, $september->credit_card_id);
        $this->assertNull($september->payment_account_id);

        $fixedExpense->update(['credit_card_id' => null, 'payment_account_id' => $account->id]);
        $action->handle($fixedExpense->user, 2026, 10);
        $october = $fixedExpense->expenses()->where('occurrence_month', 10)->sole();

        $this->assertSame($card->id, $september->refresh()->credit_card_id);
        $this->assertNull($september->payment_account_id);
        $this->assertSame($account->id, $october->payment_account_id);
        $this->assertNull($october->credit_card_id);
    }

    public function test_expense_and_fixed_expense_lists_label_payment_source_separately_from_recurrence_source(): void
    {
        $this->travelTo('2026-09-07 12:00:00');
        $expense = Expense::factory()->create(['expense_date' => '2026-09-10']);
        $card = CreditCard::factory()->for($expense->user)->create(['name' => 'Nubank']);
        $account = Account::factory()->for($expense->user)->create(['name' => 'Checking']);
        $expense->update(['credit_card_id' => $card->id]);
        FixedExpense::factory()->for($expense->user)->create(['payment_account_id' => $account->id]);
        $this->actingAs($expense->user);

        $this->get(route('expenses.index'))
            ->assertSeeTextInOrder(['Source', 'Payment Source'])
            ->assertSeeText('Manual')
            ->assertSeeText('Nubank');
        $this->get(route('fixed-expenses.index'))
            ->assertSeeText('Payment Source')
            ->assertSeeText('Checking');
    }

    public function test_materialization_preserves_an_unspecified_source_and_deleted_occurrence_identity(): void
    {
        $fixedExpense = FixedExpense::factory()->create();
        $action = app(MaterializeFixedExpensesForMonth::class);

        $action->handle($fixedExpense->user, 2026, 9);
        $expense = $fixedExpense->expenses()->sole();
        $this->assertNull($expense->payment_account_id);
        $this->assertNull($expense->credit_card_id);
        $expense->deleted_by_user_at = now();
        $expense->save();

        $action->handle($fixedExpense->user, 2026, 9);

        $this->assertSame(1, $fixedExpense->expenses()->count());
        $this->assertNotNull($expense->refresh()->deleted_by_user_at);
    }

    public function test_admin_cannot_inject_another_users_payment_source(): void
    {
        $category = ExpenseCategory::factory()->create();
        $category->user->forceFill(['role' => 'admin'])->save();
        $foreignAccount = Account::factory()->create();
        $foreignCard = CreditCard::factory()->create();
        $this->actingAs($category->user);

        foreach (['account:'.$foreignAccount->id, 'credit-card:'.$foreignCard->id] as $selection) {
            Livewire::test('pages::expenses.form')
                ->set($this->expenseFields($category) + ['payment_source' => $selection])
                ->call('save')
                ->assertHasErrors('payment_source');
        }

        $this->assertSame(0, $category->user->expenses()->count());
    }

    #[TestWith(['pages::expenses.form', 'payment_account_id'])]
    #[TestWith(['pages::expenses.form', 'credit_card_id'])]
    #[TestWith(['pages::fixed-expenses.form', 'payment_account_id'])]
    #[TestWith(['pages::fixed-expenses.form', 'credit_card_id'])]
    public function test_payment_source_foreign_keys_are_not_public_livewire_state(string $component, string $field): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);

        $this->expectException(PublicPropertyNotFoundException::class);
        Livewire::test($component)->set($field, 1);
    }

    /** @return array<string, mixed> */
    private function expenseFields(ExpenseCategory $category): array
    {
        return [
            'name' => 'Groceries',
            'expense_category_id' => $category->id,
            'amount' => '50.00',
            'expense_date' => '2026-09-10',
        ];
    }

    /** @return array<string, mixed> */
    private function fixedExpenseFields(ExpenseCategory $category): array
    {
        return [
            'name' => 'Subscription',
            'expense_category_id' => $category->id,
            'amount' => '25.00',
            'day_of_month' => 10,
            'start_date' => '2026-09-01',
            'is_active' => true,
        ];
    }
}
