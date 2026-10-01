<?php

namespace Tests\Feature;

use App\Actions\CreateStatementImport;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\StatementImport;
use App\Models\User;
use App\StatementDocumentType;
use App\StatementImportStatus;
use App\StatementInstitution;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class StatementImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_verified_active_user_can_view_empty_state_and_navigation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('statement-imports.index'))
            ->assertOk()
            ->assertSeeText('No statements imported yet.')
            ->assertSeeText('Upload a PDF statement to begin.')
            ->assertSee('href="'.route('statement-imports.index').'"', false)
            ->assertSeeText('Bank Statement')
            ->assertSeeText('Credit Card Bill')
            ->assertSeeText('Itaú')
            ->assertDontSeeText('Santander')
            ->assertDontSeeText('Nubank');
    }

    public function test_bank_statement_is_stored_privately_with_trusted_context_hash_and_uploaded_metadata_only(): void
    {
        Storage::fake('statement-imports');
        Storage::fake('public');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['name' => 'Itaú Checking']);
        $this->actingAs($user);

        Livewire::test('pages::statement-imports.index')
            ->set($this->bankStatementFields($account))
            ->set('statement', $this->validPdf('Extrato Itaú Setembro 2026.pdf'))
            ->call('createImport')
            ->assertHasNoErrors()
            ->assertSeeText('Statement uploaded securely.')
            ->assertSeeText('Extrato Itaú Setembro 2026.pdf')
            ->assertSeeText('Bank Statement')
            ->assertSeeText('Itaú Checking')
            ->assertSeeText('Uploaded');

        $statementImport = $user->statementImports()->sole();
        $this->assertSame('Extrato Itaú Setembro 2026.pdf', $statementImport->original_filename);
        $this->assertSame('statement-imports', $statementImport->storage_disk);
        $this->assertSame(StatementImportStatus::Uploaded, $statementImport->status);
        $this->assertSame(StatementDocumentType::BankStatement, $statementImport->document_type);
        $this->assertSame(StatementInstitution::Itau, $statementImport->institution);
        $this->assertSame($account->id, $statementImport->account_id);
        $this->assertNull($statementImport->credit_card_id);
        $this->assertNull($statementImport->bill_due_year);
        $this->assertNull($statementImport->bill_due_month);
        $this->assertSame('4218d165efcf496027930f361d0ccc184d0f2272619f23d3597f2596079ecaf5', $statementImport->file_sha256);
        $this->assertNull($statementImport->failure_message);
        $this->assertNull($statementImport->imported_at);
        $this->assertTrue($statementImport->hasImportContext());
        $this->assertTrue($statementImport->account->is($account));
        $this->assertMatchesRegularExpression('/^'.$user->id.'\/[0-9a-f-]{36}\.pdf$/', $statementImport->storage_path);
        Storage::disk('statement-imports')->assertExists($statementImport->storage_path);
        Storage::disk('public')->assertDirectoryEmpty('/');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_credit_card_bill_requires_and_displays_owned_card_and_due_month_context(): void
    {
        Storage::fake('statement-imports');
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->for($user)->create(['name' => 'Itaú Multi Black']);
        $this->actingAs($user);

        Livewire::test('pages::statement-imports.index')
            ->set($this->creditCardBillFields($creditCard, '2026-10'))
            ->set('statement', $this->validPdf('Fatura outubro.pdf'))
            ->call('createImport')
            ->assertHasNoErrors()
            ->assertSeeText('Credit Card Bill')
            ->assertSeeText('Itaú')
            ->assertSeeText('Itaú Multi Black')
            ->assertSeeText('October 2026');

        $statementImport = $user->statementImports()->sole();
        $this->assertSame(StatementDocumentType::CreditCardBill, $statementImport->document_type);
        $this->assertSame(StatementInstitution::Itau, $statementImport->institution);
        $this->assertNull($statementImport->account_id);
        $this->assertSame($creditCard->id, $statementImport->credit_card_id);
        $this->assertSame(2026, $statementImport->bill_due_year);
        $this->assertSame(10, $statementImport->bill_due_month);
        $this->assertTrue($statementImport->creditCard->is($creditCard));
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_switching_document_type_clears_incompatible_source_and_due_month_state(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $creditCard = CreditCard::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test('pages::statement-imports.index')
            ->set($this->creditCardBillFields($creditCard, '2026-10'))
            ->set('document_type', StatementDocumentType::BankStatement->value)
            ->assertSet('credit_card_id', '')
            ->assertSet('bill_due_month', '')
            ->set('account_id', (string) $account->id)
            ->set('document_type', StatementDocumentType::CreditCardBill->value)
            ->assertSet('account_id', '');
    }

    public function test_crafted_bank_statement_due_month_and_unsupported_enum_values_are_rejected(): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $this->actingAs($account->user);

        Livewire::test('pages::statement-imports.index')
            ->set($this->bankStatementFields($account))
            ->set('bill_due_month', '2026-10')
            ->set('statement', $this->validPdf())
            ->call('createImport')
            ->assertHasErrors(['bill_due_month' => 'prohibited']);

        Livewire::test('pages::statement-imports.index')
            ->set('document_type', 'investment_report')
            ->set('institution', 'unsupported-bank')
            ->set('statement', $this->validPdf())
            ->call('createImport')
            ->assertHasErrors(['document_type', 'institution']);

        $this->assertSame(0, StatementImport::query()->count());
        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    #[TestWith(['user', 'account'])]
    #[TestWith(['admin', 'account'])]
    #[TestWith(['user', 'credit_card'])]
    #[TestWith(['admin', 'credit_card'])]
    public function test_foreign_financial_source_injection_is_rejected_without_admin_bypass(string $role, string $sourceType): void
    {
        Storage::fake('statement-imports');
        $owner = User::factory()->create();
        $attacker = User::factory()->create(['role' => UserRole::from($role)]);
        $this->actingAs($attacker);
        $component = Livewire::test('pages::statement-imports.index')
            ->set('institution', StatementInstitution::Itau->value)
            ->set('include_inactive_sources', true)
            ->set('statement', $this->validPdf());

        if ($sourceType === 'account') {
            $foreignSource = Account::factory()->for($owner)->inactive()->create(['name' => 'Private historical account']);
            $component->set('document_type', StatementDocumentType::BankStatement->value)
                ->set('account_id', (string) $foreignSource->id)
                ->call('createImport')
                ->assertHasErrors('account_id')
                ->assertSeeText('The selected account is unavailable.');
        } else {
            $foreignSource = CreditCard::factory()->for($owner)->inactive()->create(['name' => 'Private historical card']);
            $component->set('document_type', StatementDocumentType::CreditCardBill->value)
                ->set('credit_card_id', (string) $foreignSource->id)
                ->set('bill_due_month', '2026-10')
                ->call('createImport')
                ->assertHasErrors('credit_card_id')
                ->assertSeeText('The selected credit card is unavailable.');
        }

        $this->assertSame(0, StatementImport::query()->count());
        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    #[TestWith(['bank_statement', false, false, null, null])]
    #[TestWith(['bank_statement', false, true, null, null])]
    #[TestWith(['bank_statement', true, true, null, null])]
    #[TestWith(['bank_statement', true, false, 2026, 10])]
    #[TestWith(['credit_card_bill', false, false, 2026, 10])]
    #[TestWith(['credit_card_bill', true, false, 2026, 10])]
    #[TestWith(['credit_card_bill', true, true, 2026, 10])]
    public function test_action_rejects_wrong_source_combinations_before_storage(
        string $documentType,
        bool $withAccount,
        bool $withCreditCard,
        ?int $billDueYear,
        ?int $billDueMonth,
    ): void {
        Storage::fake('statement-imports');
        $user = User::factory()->create();
        $account = $withAccount ? Account::factory()->for($user)->create() : null;
        $creditCard = $withCreditCard ? CreditCard::factory()->for($user)->create() : null;

        try {
            app(CreateStatementImport::class)->handle(
                $user,
                $this->validPdf(),
                StatementDocumentType::from($documentType),
                StatementInstitution::Itau,
                $account,
                $creditCard,
                $billDueYear,
                $billDueMonth,
            );
            $this->fail('Invalid import context was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, $user->statementImports()->count());
        }

        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    #[TestWith([null, null])]
    #[TestWith([2026, 0])]
    #[TestWith([2026, 13])]
    #[TestWith([-1, 10])]
    #[TestWith([10000, 10])]
    public function test_action_rejects_missing_or_invalid_credit_card_due_month(?int $year, ?int $month): void
    {
        Storage::fake('statement-imports');
        $creditCard = CreditCard::factory()->create();

        try {
            app(CreateStatementImport::class)->handle(
                user: $creditCard->user,
                file: $this->validPdf(),
                documentType: StatementDocumentType::CreditCardBill,
                institution: StatementInstitution::Itau,
                creditCard: $creditCard,
                billDueYear: $year,
                billDueMonth: $month,
            );
            $this->fail('Invalid due month context was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, StatementImport::query()->count());
        }

        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    #[TestWith(['2026-00'])]
    #[TestWith(['2026-13'])]
    #[TestWith(['-1-10'])]
    #[TestWith(['not-a-month'])]
    public function test_upload_form_rejects_malformed_due_month_values(string $dueMonth): void
    {
        Storage::fake('statement-imports');
        $creditCard = CreditCard::factory()->create();
        $this->actingAs($creditCard->user);

        Livewire::test('pages::statement-imports.index')
            ->set($this->creditCardBillFields($creditCard, $dueMonth))
            ->set('statement', $this->validPdf())
            ->call('createImport')
            ->assertHasErrors('bill_due_month')
            ->assertSeeText('Choose a valid bill due month.');

        $this->assertSame(0, StatementImport::query()->count());
        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    #[TestWith(['account'])]
    #[TestWith(['credit_card'])]
    public function test_inactive_owned_sources_require_and_work_through_historical_mode(string $sourceType): void
    {
        Storage::fake('statement-imports');
        $user = User::factory()->create();
        $activeSource = $sourceType === 'account'
            ? Account::factory()->for($user)->create(['name' => 'Current source'])
            : CreditCard::factory()->for($user)->create(['name' => 'Current source']);
        $inactiveSource = $sourceType === 'account'
            ? Account::factory()->for($user)->inactive()->create(['name' => 'Historical source'])
            : CreditCard::factory()->for($user)->inactive()->create(['name' => 'Historical source']);
        $this->actingAs($user);

        $component = Livewire::test('pages::statement-imports.index')
            ->set('document_type', $sourceType === 'account' ? StatementDocumentType::BankStatement->value : StatementDocumentType::CreditCardBill->value)
            ->assertSeeText($activeSource->name)
            ->assertDontSeeText($inactiveSource->name)
            ->set('include_inactive_sources', true)
            ->assertSeeText('Historical source (Inactive)')
            ->set('institution', StatementInstitution::Itau->value)
            ->set('statement', $this->validPdf());

        if ($sourceType === 'account') {
            $component->set('account_id', (string) $inactiveSource->id);
        } else {
            $component->set('credit_card_id', (string) $inactiveSource->id)->set('bill_due_month', '2026-10');
        }

        $component->call('createImport')->assertHasNoErrors();
        $this->assertFalse($inactiveSource->refresh()->is_active);
        $this->assertSame($inactiveSource->id, $user->statementImports()->sole()->{$sourceType.'_id'});
    }

    public function test_disabling_historical_mode_clears_an_inactive_selection(): void
    {
        $account = Account::factory()->inactive()->create();
        $this->actingAs($account->user);

        Livewire::test('pages::statement-imports.index')
            ->set('document_type', StatementDocumentType::BankStatement->value)
            ->set('include_inactive_sources', true)
            ->set('account_id', (string) $account->id)
            ->set('include_inactive_sources', false)
            ->assertSet('account_id', '');
    }

    public function test_same_file_may_be_uploaded_twice_by_the_same_user(): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $this->actingAs($account->user);
        $component = Livewire::test('pages::statement-imports.index')->set($this->bankStatementFields($account));

        $component->set('statement', $this->validPdf('first.pdf'))->call('createImport')->assertHasNoErrors();
        $component->set('statement', $this->validPdf('second.pdf'))->call('createImport')->assertHasNoErrors();

        $imports = $account->user->statementImports()->orderBy('id')->get();
        $this->assertCount(2, $imports);
        $this->assertSame($imports[0]->file_sha256, $imports[1]->file_sha256);
        $this->assertNotSame($imports[0]->storage_path, $imports[1]->storage_path);
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_identical_file_hashes_remain_private_and_independent_across_users(): void
    {
        Storage::fake('statement-imports');
        $firstAccount = Account::factory()->create();
        $secondAccount = Account::factory()->create();

        foreach ([$firstAccount, $secondAccount] as $index => $account) {
            $this->actingAs($account->user);
            Livewire::test('pages::statement-imports.index')
                ->set($this->bankStatementFields($account))
                ->set('statement', $this->validPdf($index === 0 ? 'first-user.pdf' : 'second-user-private.pdf'))
                ->call('createImport')
                ->assertHasNoErrors();
        }

        $firstImport = $firstAccount->user->statementImports()->sole();
        $secondImport = $secondAccount->user->statementImports()->sole();
        $this->assertSame($firstImport->file_sha256, $secondImport->file_sha256);
        $this->assertNotSame($firstImport->user_id, $secondImport->user_id);

        $this->actingAs($firstAccount->user)
            ->get(route('statement-imports.index'))
            ->assertDontSee($firstImport->file_sha256)
            ->assertDontSeeText($secondImport->original_filename);
    }

    public function test_legacy_contextless_import_remains_visible_uploaded_and_requires_context(): void
    {
        $legacyImport = StatementImport::factory()->create(['original_filename' => 'Legacy statement.pdf']);
        $this->actingAs($legacyImport->user);

        $this->get(route('statement-imports.index'))
            ->assertOk()
            ->assertSeeText('Legacy statement.pdf')
            ->assertSeeText('Context required')
            ->assertSeeText('Uploaded')
            ->assertDontSeeText('Not detected');

        $legacyImport->refresh();
        $this->assertNull($legacyImport->document_type);
        $this->assertNull($legacyImport->institution);
        $this->assertNull($legacyImport->account_id);
        $this->assertNull($legacyImport->credit_card_id);
        $this->assertNull($legacyImport->bill_due_year);
        $this->assertNull($legacyImport->bill_due_month);
        $this->assertNull($legacyImport->file_sha256);
        $this->assertSame(StatementImportStatus::Uploaded, $legacyImport->status);
        $this->assertFalse($legacyImport->hasImportContext());
    }

    public function test_physical_path_never_uses_the_client_filename(): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $this->actingAs($account->user);

        Livewire::test('pages::statement-imports.index')
            ->set($this->bankStatementFields($account))
            ->set('statement', $this->validPdf('../../dangerous name ç.pdf'))
            ->call('createImport')
            ->assertHasNoErrors();

        $statementImport = $account->user->statementImports()->sole();
        $this->assertStringNotContainsString('dangerous', $statementImport->storage_path);
        $this->assertStringNotContainsString('..', $statementImport->storage_path);
        $this->assertStringNotContainsString(' ', $statementImport->storage_path);
        $this->assertMatchesRegularExpression('/^'.$account->user_id.'\/[0-9a-f-]{36}\.pdf$/', $statementImport->storage_path);
    }

    #[TestWith(['renamed-image.pdf', "\x89PNG\r\n\x1a\nimage data", 'image/png'])]
    #[TestWith(['renamed-text.pdf', 'plain text', 'text/plain'])]
    #[TestWith(['renamed-html.pdf', '<html><body>Not a PDF</body></html>', 'text/html'])]
    #[TestWith(['renamed-archive.pdf', "PK\x03\x04archive data", 'application/zip'])]
    public function test_non_pdf_content_is_rejected_even_with_pdf_extension(string $filename, string $contents, string $detectedMimeType): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $this->actingAs($account->user);

        Livewire::test('pages::statement-imports.index')
            ->set($this->bankStatementFields($account))
            ->set('statement', UploadedFile::fake()->createWithContent($filename, $contents)->mimeType($detectedMimeType))
            ->call('createImport')
            ->assertHasErrors(['statement']);

        $this->assertSame(0, $account->user->statementImports()->count());
        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    public function test_pdf_larger_than_ten_megabytes_is_rejected(): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $this->actingAs($account->user);
        $oversizedPdf = UploadedFile::fake()->createWithContent(
            'large.pdf',
            "%PDF-1.4\n".str_repeat('0', 10 * 1024 * 1024),
        )->mimeType('application/pdf');

        Livewire::test('pages::statement-imports.index')
            ->set($this->bankStatementFields($account))
            ->set('statement', $oversizedPdf)
            ->call('createImport')
            ->assertHasErrors(['statement'])
            ->assertSeeText('The statement must not be larger than 10 MB.');

        $this->assertSame(0, $account->user->statementImports()->count());
        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    public function test_statement_imports_require_authentication_verification_and_active_account(): void
    {
        $this->get(route('statement-imports.index'))->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)
            ->get(route('statement-imports.index'))
            ->assertRedirect(route('verification.notice'));

        $activeUser = User::factory()->create();
        $this->actingAs($activeUser);
        $activeUser->is_active = false;
        $activeUser->save();

        $this->get(route('statement-imports.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_history_and_policy_never_disclose_foreign_imports(string $role): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create(['role' => UserRole::from($role)]);
        StatementImport::factory()->for($owner)->create(['original_filename' => 'Owner private.pdf']);
        StatementImport::factory()->for($viewer)->create(['original_filename' => 'Viewer statement.pdf']);
        $this->actingAs($viewer);

        $this->get(route('statement-imports.index'))
            ->assertOk()
            ->assertSeeText('Viewer statement.pdf')
            ->assertDontSeeText('Owner private.pdf');

        $foreignImport = $owner->statementImports()->sole();
        $this->assertSame(404, Gate::forUser($viewer)->inspect('view', $foreignImport)->status());
    }

    public function test_policy_allows_listing_and_upload_but_no_mutation_or_admin_bypass(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $ownedImport = StatementImport::factory()->for($admin)->create();
        $foreignImport = StatementImport::factory()->create();
        $gate = Gate::forUser($admin);

        $this->assertTrue($gate->allows('viewAny', StatementImport::class));
        $this->assertTrue($gate->allows('create', StatementImport::class));
        $this->assertTrue($gate->allows('view', $ownedImport));
        $this->assertSame(404, $gate->inspect('view', $foreignImport)->status());
        $this->assertFalse($gate->allows('update', $ownedImport));
        $this->assertFalse($gate->allows('delete', $ownedImport));
        $this->assertFalse($gate->allows('restore', $ownedImport));
        $this->assertFalse($gate->allows('forceDelete', $ownedImport));
    }

    public function test_no_detail_download_or_delete_endpoint_is_exposed(): void
    {
        $statementImport = StatementImport::factory()->create();
        $this->actingAs($statementImport->user);

        $this->get('/statement-imports/'.$statementImport->id)->assertNotFound();
        $this->get('/statement-imports/'.$statementImport->id.'/download')->assertNotFound();
        $this->delete('/statement-imports/'.$statementImport->id)->assertNotFound();
        $this->assertFalse(method_exists(Livewire::test('pages::statement-imports.index')->instance(), 'delete'));
    }

    public function test_original_filename_is_escaped_and_internal_storage_and_hash_details_are_not_rendered(): void
    {
        $statementImport = StatementImport::factory()->create([
            'original_filename' => '<script>alert("private")</script>.pdf',
            'storage_path' => '42/private-storage-name.pdf',
            'file_sha256' => str_repeat('a', 64),
        ]);
        $this->actingAs($statementImport->user);

        $this->get(route('statement-imports.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;private&quot;)&lt;/script&gt;.pdf', false)
            ->assertDontSee('<script>alert("private")</script>', false)
            ->assertDontSee('private-storage-name.pdf')
            ->assertDontSee(str_repeat('a', 64))
            ->assertDontSeeText('statement-imports');
    }

    public function test_invalid_context_is_rejected_before_file_storage(): void
    {
        Storage::fake('statement-imports');
        $user = User::factory()->create();
        $foreignAccount = Account::factory()->create();

        try {
            app(CreateStatementImport::class)->handle(
                user: $user,
                file: $this->validPdf(),
                documentType: StatementDocumentType::BankStatement,
                institution: StatementInstitution::Itau,
                account: $foreignAccount,
            );
            $this->fail('Foreign context was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, StatementImport::query()->count());
        }

        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    public function test_stored_file_is_removed_when_database_creation_fails(): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $event = 'eloquent.creating: '.StatementImport::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('Simulated database failure.');
        });

        try {
            app(CreateStatementImport::class)->handle(
                user: $account->user,
                file: $this->validPdf(),
                documentType: StatementDocumentType::BankStatement,
                institution: StatementInstitution::Itau,
                account: $account,
            );
            $this->fail('The simulated database failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, StatementImport::query()->count());
        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    public function test_factory_does_not_create_a_file_and_user_deletion_cascades_legacy_metadata(): void
    {
        Storage::fake('statement-imports');
        $statementImport = StatementImport::factory()->create();

        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
        $statementImport->user->delete();

        $this->assertDatabaseMissing('statement_imports', ['id' => $statementImport->id]);
    }

    public function test_whole_user_deletion_removes_contextual_imports_before_restrictive_sources(): void
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

    /** @return array<string, string> */
    private function bankStatementFields(Account $account): array
    {
        return [
            'document_type' => StatementDocumentType::BankStatement->value,
            'institution' => StatementInstitution::Itau->value,
            'account_id' => (string) $account->id,
        ];
    }

    /** @return array<string, string> */
    private function creditCardBillFields(CreditCard $creditCard, string $dueMonth): array
    {
        return [
            'document_type' => StatementDocumentType::CreditCardBill->value,
            'institution' => StatementInstitution::Itau->value,
            'credit_card_id' => (string) $creditCard->id,
            'bill_due_month' => $dueMonth,
        ];
    }

    private function validPdf(string $filename = 'statement.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF",
        )->mimeType('application/pdf');
    }
}
