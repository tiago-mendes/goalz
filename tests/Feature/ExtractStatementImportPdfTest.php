<?php

namespace Tests\Feature;

use App\Actions\ExtractStatementImportPdf;
use App\Contracts\PdfTextExtractor;
use App\Exceptions\PdfExtractionException;
use App\ExtractedPdf;
use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\CreditCardBillPeriod;
use App\Models\Expense;
use App\Models\FixedExpense;
use App\Models\MonthlyIncome;
use App\Models\StatementImport;
use App\Models\User;
use App\StatementDocumentType;
use App\StatementImportStatus;
use App\StatementInstitution;
use App\Support\PopplerPdfTextExtractor;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExtractStatementImportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_resolves_goalz_poppler_extractor_for_contract(): void
    {
        $this->assertInstanceOf(PopplerPdfTextExtractor::class, app(PdfTextExtractor::class));
    }

    public function test_owner_extracts_complete_context_import_without_persisted_or_financial_changes(): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create(['current_balance' => '321.09']);
        $statementImport = $this->completeImport($account->user, $account);
        Storage::disk('statement-imports')->put($statementImport->storage_path, '%PDF-1.4 synthetic private bytes');
        $expected = new ExtractedPdf(['synthetic page']);
        $extractor = new FakePdfTextExtractor($expected);

        $result = (new ExtractStatementImportPdf($extractor))->handle($account->user, $statementImport);

        $this->assertSame($expected, $result);
        $this->assertSame(Storage::disk('statement-imports')->path($statementImport->storage_path), $extractor->receivedPath);
        $this->assertSame(1, $extractor->calls);
        $this->assertSame(StatementImportStatus::Uploaded, $statementImport->refresh()->status);
        $this->assertNull($statementImport->imported_at);
        $this->assertSame('321.09', $account->refresh()->current_balance);
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, FixedExpense::query()->count());
        $this->assertSame(0, MonthlyIncome::query()->count());
        $this->assertSame(0, AccountBalanceSnapshot::query()->count());
        $this->assertSame(0, CreditCardBillPeriod::query()->count());
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_foreign_user_cannot_extract_import_without_admin_bypass(string $role): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $statementImport = $this->completeImport($account->user, $account);
        Storage::disk('statement-imports')->put($statementImport->storage_path, '%PDF-1.4 synthetic private bytes');
        $extractor = new FakePdfTextExtractor(new ExtractedPdf(['must not be returned']));
        $foreignUser = User::factory()->create(['role' => UserRole::from($role)]);

        $this->assertExtractionFailure(
            'unavailable_import',
            fn () => (new ExtractStatementImportPdf($extractor))->handle($foreignUser, $statementImport),
        );

        $this->assertSame(0, $extractor->calls);
    }

    public function test_legacy_contextless_import_is_rejected_before_file_access(): void
    {
        Storage::fake('statement-imports');
        $statementImport = StatementImport::factory()->create();
        Storage::disk('statement-imports')->put($statementImport->storage_path, '%PDF-1.4 legacy bytes');
        $extractor = new FakePdfTextExtractor(new ExtractedPdf(['must not be returned']));

        $this->assertExtractionFailure(
            'incomplete_context',
            fn () => (new ExtractStatementImportPdf($extractor))->handle($statementImport->user, $statementImport),
        );

        $this->assertSame(0, $extractor->calls);
    }

    public function test_missing_private_file_fails_safely_without_calling_extractor(): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $statementImport = $this->completeImport($account->user, $account);
        $extractor = new FakePdfTextExtractor(new ExtractedPdf(['must not be returned']));

        $this->assertExtractionFailure(
            'missing_file',
            fn () => (new ExtractStatementImportPdf($extractor))->handle($account->user, $statementImport),
        );

        $this->assertSame(0, $extractor->calls);
    }

    public function test_private_storage_symlink_cannot_escape_configured_disk(): void
    {
        Storage::fake('statement-imports');
        $account = Account::factory()->create();
        $statementImport = $this->completeImport($account->user, $account);
        $extractor = new FakePdfTextExtractor(new ExtractedPdf(['must not be returned']));
        $outsidePath = tempnam(sys_get_temp_dir(), 'goalz-outside-import-');
        $this->assertNotFalse($outsidePath);
        file_put_contents($outsidePath, '%PDF-1.4 outside private disk');
        Storage::disk('statement-imports')->makeDirectory(dirname($statementImport->storage_path));
        $symlinkPath = Storage::disk('statement-imports')->path($statementImport->storage_path);
        $this->assertTrue(symlink($outsidePath, $symlinkPath));

        try {
            $this->assertExtractionFailure(
                'missing_file',
                fn () => (new ExtractStatementImportPdf($extractor))->handle($account->user, $statementImport),
            );
        } finally {
            unlink($symlinkPath);
            unlink($outsidePath);
        }

        $this->assertSame(0, $extractor->calls);
    }

    private function completeImport(User $user, Account $account): StatementImport
    {
        return StatementImport::factory()->for($user)->create([
            'document_type' => StatementDocumentType::BankStatement,
            'institution' => StatementInstitution::Itau,
            'account_id' => $account->id,
            'file_sha256' => str_repeat('a', 64),
        ]);
    }

    /** @param callable(): mixed $callback */
    private function assertExtractionFailure(string $category, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected PDF extraction to fail.');
        } catch (PdfExtractionException $exception) {
            $this->assertSame($category, $exception->category);
            $this->assertSame('The PDF could not be extracted.', $exception->getMessage());
        }
    }
}

final class FakePdfTextExtractor implements PdfTextExtractor
{
    public ?string $receivedPath = null;

    public int $calls = 0;

    public function __construct(private readonly ExtractedPdf $result) {}

    public function extract(string $absolutePdfPath): ExtractedPdf
    {
        $this->receivedPath = $absolutePdfPath;
        $this->calls++;

        return $this->result;
    }
}
