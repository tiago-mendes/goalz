<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CreditCard;
use App\Models\StatementImport;
use App\Models\User;
use App\StatementDocumentType;
use App\StatementInstitution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/** Runs against migrated MariaDB with every fixture rolled back. */
class StatementImportsMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify statement import constraints.');
        }
    }

    public function test_database_applies_uploaded_default_and_cascades_with_user_deletion(): void
    {
        $user = User::factory()->create();
        $statementImportId = DB::table('statement_imports')->insertGetId([
            'user_id' => $user->id,
            'original_filename' => 'statement.pdf',
            'storage_disk' => 'statement-imports',
            'storage_path' => $user->id.'/generated.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $statementImport = StatementImport::query()->findOrFail($statementImportId);

        $this->assertSame('uploaded', $statementImport->getRawOriginal('status'));

        $user->delete();

        $this->assertModelMissing($statementImport);
    }

    public function test_database_rejects_a_missing_owner(): void
    {
        $this->expectException(QueryException::class);
        DB::table('statement_imports')->insert([
            'user_id' => 0,
            'original_filename' => 'statement.pdf',
            'storage_disk' => 'statement-imports',
            'storage_path' => '0/generated.pdf',
            'status' => 'uploaded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[TestWith(['account_id'])]
    #[TestWith(['credit_card_id'])]
    public function test_financial_source_foreign_keys_must_exist(string $field): void
    {
        $statementImport = StatementImport::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('statement_imports')->where('id', $statementImport->id)->update([$field => 0]);
    }

    #[TestWith(['account'])]
    #[TestWith(['credit_card'])]
    public function test_referenced_financial_sources_cannot_be_hard_deleted(string $sourceType): void
    {
        $source = $sourceType === 'account' ? Account::factory()->create() : CreditCard::factory()->create();
        StatementImport::factory()->for($source->user)->create([
            'document_type' => $sourceType === 'account'
                ? StatementDocumentType::BankStatement
                : StatementDocumentType::CreditCardBill,
            'institution' => StatementInstitution::Itau,
            $sourceType.'_id' => $source->id,
            'bill_due_year' => $sourceType === 'credit_card' ? 2026 : null,
            'bill_due_month' => $sourceType === 'credit_card' ? 10 : null,
        ]);

        $this->expectException(QueryException::class);
        $source->delete();
    }

    public function test_nullable_legacy_context_and_enum_strings_persist_on_mariadb(): void
    {
        $legacyImport = StatementImport::factory()->create();
        $account = Account::factory()->for($legacyImport->user)->create();
        $contextualImport = StatementImport::factory()->for($legacyImport->user)->create([
            'document_type' => StatementDocumentType::BankStatement,
            'institution' => StatementInstitution::Itau,
            'account_id' => $account->id,
        ]);

        $this->assertDatabaseHas('statement_imports', [
            'id' => $legacyImport->id,
            'document_type' => null,
            'institution' => null,
            'account_id' => null,
            'credit_card_id' => null,
            'bill_due_year' => null,
            'bill_due_month' => null,
            'file_sha256' => null,
        ]);
        $this->assertSame('bank_statement', $contextualImport->getRawOriginal('document_type'));
        $this->assertSame('itau', $contextualImport->getRawOriginal('institution'));
    }

    public function test_owner_hash_index_is_non_unique_and_has_expected_column_order(): void
    {
        $user = User::factory()->create();
        $hash = str_repeat('a', 64);
        StatementImport::factory()->count(2)->for($user)->create(['file_sha256' => $hash]);
        StatementImport::factory()->create(['file_sha256' => $hash]);

        $indexColumns = collect(DB::select(
            <<<'SQL'
                SELECT index_name, non_unique, column_name, seq_in_index
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                    AND table_name = 'statement_imports'
                    AND index_name = 'statement_imports_user_id_file_sha256_index'
                ORDER BY seq_in_index
                SQL,
        ));

        $this->assertSame(['user_id', 'file_sha256'], $indexColumns->pluck('column_name')->all());
        $this->assertSame([1, 1], $indexColumns->pluck('non_unique')->map(fn ($value): int => (int) $value)->all());
        $this->assertSame(3, StatementImport::query()->where('file_sha256', $hash)->count());
    }

    public function test_whole_user_deletion_removes_imports_before_restrictive_sources_on_mariadb(): void
    {
        $account = Account::factory()->create();
        $creditCard = CreditCard::factory()->for($account->user)->create();
        StatementImport::factory()->for($account->user)->create([
            'document_type' => StatementDocumentType::BankStatement,
            'institution' => StatementInstitution::Itau,
            'account_id' => $account->id,
        ]);
        StatementImport::factory()->for($account->user)->create([
            'document_type' => StatementDocumentType::CreditCardBill,
            'institution' => StatementInstitution::Itau,
            'credit_card_id' => $creditCard->id,
            'bill_due_year' => 2026,
            'bill_due_month' => 10,
        ]);
        $userId = $account->user_id;
        $this->actingAs($account->user);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertDatabaseMissing('statement_imports', ['user_id' => $userId]);
        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('credit_cards', ['id' => $creditCard->id]);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }
}
