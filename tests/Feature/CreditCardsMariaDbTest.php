<?php

namespace Tests\Feature;

use App\Actions\CalculateCreditCardBill;
use App\BillingCycleResolver;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\FixedExpense;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/** Runs against migrated MariaDB with every fixture rolled back. */
class CreditCardsMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify credit card constraints and exact decimal storage.');
        }
    }

    #[TestWith(['cycle_start_day', 0])]
    #[TestWith(['cycle_start_day', 32])]
    #[TestWith(['due_day', 0])]
    #[TestWith(['due_day', 32])]
    public function test_database_rejects_invalid_billing_days(string $field, int $value): void
    {
        $this->expectException(QueryException::class);
        CreditCard::factory()->create([$field => $value]);
    }

    public function test_name_uniqueness_is_case_insensitive_per_user(): void
    {
        $creditCard = CreditCard::factory()->create(['name' => 'Nubank']);

        $this->expectException(QueryException::class);
        CreditCard::factory()->for($creditCard->user)->create(['name' => 'nubank']);
    }

    #[TestWith(['expenses'])]
    #[TestWith(['fixed_expenses'])]
    public function test_database_rejects_two_payment_sources(string $table): void
    {
        $account = Account::factory()->create();
        $card = CreditCard::factory()->for($account->user)->create();
        $record = $table === 'expenses'
            ? Expense::factory()->for($account->user)->create()
            : FixedExpense::factory()->for($account->user)->create();

        $this->expectException(QueryException::class);
        DB::table($table)->where('id', $record->id)->update([
            'payment_account_id' => $account->id,
            'credit_card_id' => $card->id,
        ]);
    }

    #[TestWith(['payment_account_id'])]
    #[TestWith(['credit_card_id'])]
    public function test_expense_payment_source_foreign_keys_must_exist(string $field): void
    {
        $expense = Expense::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('expenses')->where('id', $expense->id)->update([$field => 0]);
    }

    public function test_referenced_payment_sources_cannot_be_hard_deleted(): void
    {
        $card = CreditCard::factory()->create();
        Expense::factory()->for($card->user)->create(['credit_card_id' => $card->id]);

        $this->expectException(QueryException::class);
        $card->delete();
    }

    public function test_large_bill_total_remains_exact(): void
    {
        $card = CreditCard::factory()->create(['cycle_start_day' => 13, 'due_day' => 20]);
        Expense::factory()->for($card->user)->count(2)->create([
            'credit_card_id' => $card->id,
            'expense_date' => '2026-09-20',
            'amount' => '9999999999999.99',
        ]);
        $cycle = app(BillingCycleResolver::class)->dueIn(CarbonImmutable::parse('2026-10-01'), 13, 20);

        $bill = app(CalculateCreditCardBill::class)->handle($card->user, $card, $cycle);

        $this->assertSame('19999999999999.98', $bill->total);
    }

    public function test_whole_user_deletion_removes_payment_sources_and_references_safely(): void
    {
        $fixedExpense = FixedExpense::factory()->create();
        $account = Account::factory()->for($fixedExpense->user)->create();
        $card = CreditCard::factory()->for($fixedExpense->user)->create();
        $fixedExpense->update(['credit_card_id' => $card->id]);
        Expense::factory()->for($fixedExpense->user)->create(['payment_account_id' => $account->id]);
        $this->actingAs($fixedExpense->user);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $fixedExpense->user_id]);
        $this->assertDatabaseMissing('credit_cards', ['user_id' => $fixedExpense->user_id]);
        $this->assertDatabaseMissing('accounts', ['user_id' => $fixedExpense->user_id]);
        $this->assertDatabaseMissing('fixed_expenses', ['user_id' => $fixedExpense->user_id]);
        $this->assertDatabaseMissing('expenses', ['user_id' => $fixedExpense->user_id]);
    }
}
