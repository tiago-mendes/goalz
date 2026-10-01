<?php

namespace Tests\Feature;

use App\Actions\CreateStatementImport;
use App\Models\Expense;
use App\Models\StatementImport;
use App\Models\User;
use App\StatementImportStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('href="'.route('statement-imports.index').'"', false);
    }

    public function test_valid_pdf_is_stored_privately_with_generated_name_and_uploaded_metadata_only(): void
    {
        Storage::fake('statement-imports');
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::statement-imports.index')
            ->set('statement', $this->validPdf('Fatura Itaú Setembro 2026.pdf'))
            ->call('upload')
            ->assertHasNoErrors()
            ->assertSeeText('Statement uploaded securely.')
            ->assertSeeText('Fatura Itaú Setembro 2026.pdf')
            ->assertSeeText('Uploaded');

        $statementImport = $user->statementImports()->sole();
        $this->assertSame('Fatura Itaú Setembro 2026.pdf', $statementImport->original_filename);
        $this->assertSame('statement-imports', $statementImport->storage_disk);
        $this->assertSame(StatementImportStatus::Uploaded, $statementImport->status);
        $this->assertNull($statementImport->source);
        $this->assertNull($statementImport->failure_message);
        $this->assertNull($statementImport->imported_at);
        $this->assertMatchesRegularExpression('/^'.$user->id.'\/[0-9a-f-]{36}\.pdf$/', $statementImport->storage_path);
        Storage::disk('statement-imports')->assertExists($statementImport->storage_path);
        Storage::disk('public')->assertDirectoryEmpty('/');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_physical_path_never_uses_the_client_filename(): void
    {
        Storage::fake('statement-imports');
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::statement-imports.index')
            ->set('statement', $this->validPdf('../../dangerous name ç.pdf'))
            ->call('upload')
            ->assertHasNoErrors();

        $statementImport = $user->statementImports()->sole();
        $this->assertStringNotContainsString('dangerous', $statementImport->storage_path);
        $this->assertStringNotContainsString('..', $statementImport->storage_path);
        $this->assertStringNotContainsString(' ', $statementImport->storage_path);
        $this->assertMatchesRegularExpression('/^'.$user->id.'\/[0-9a-f-]{36}\.pdf$/', $statementImport->storage_path);
    }

    #[TestWith(['renamed-image.pdf', "\x89PNG\r\n\x1a\nimage data", 'image/png'])]
    #[TestWith(['renamed-text.pdf', 'plain text', 'text/plain'])]
    #[TestWith(['renamed-html.pdf', '<html><body>Not a PDF</body></html>', 'text/html'])]
    #[TestWith(['renamed-archive.pdf', "PK\x03\x04archive data", 'application/zip'])]
    public function test_non_pdf_content_is_rejected_even_with_pdf_extension(string $filename, string $contents, string $detectedMimeType): void
    {
        Storage::fake('statement-imports');
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::statement-imports.index')
            ->set('statement', UploadedFile::fake()->createWithContent($filename, $contents)->mimeType($detectedMimeType))
            ->call('upload')
            ->assertHasErrors(['statement']);

        $this->assertSame(0, $user->statementImports()->count());
        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    public function test_pdf_larger_than_ten_megabytes_is_rejected(): void
    {
        Storage::fake('statement-imports');
        $user = User::factory()->create();
        $this->actingAs($user);
        $oversizedPdf = UploadedFile::fake()->createWithContent(
            'large.pdf',
            "%PDF-1.4\n".str_repeat('0', 10 * 1024 * 1024),
        )->mimeType('application/pdf');

        Livewire::test('pages::statement-imports.index')
            ->set('statement', $oversizedPdf)
            ->call('upload')
            ->assertHasErrors(['statement'])
            ->assertSeeText('The statement must not be larger than 10 MB.');

        $this->assertSame(0, $user->statementImports()->count());
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

    public function test_original_filename_is_escaped_and_internal_storage_details_are_not_rendered(): void
    {
        $statementImport = StatementImport::factory()->create([
            'original_filename' => '<script>alert("private")</script>.pdf',
            'storage_path' => '42/private-storage-name.pdf',
        ]);
        $this->actingAs($statementImport->user);

        $this->get(route('statement-imports.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;private&quot;)&lt;/script&gt;.pdf', false)
            ->assertDontSee('<script>alert("private")</script>', false)
            ->assertDontSee('private-storage-name.pdf');
    }

    public function test_stored_file_is_removed_when_database_creation_fails(): void
    {
        Storage::fake('statement-imports');
        $user = User::factory()->create();
        $event = 'eloquent.creating: '.StatementImport::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('Simulated database failure.');
        });

        try {
            app(CreateStatementImport::class)->handle($user, $this->validPdf());
            $this->fail('The simulated database failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, StatementImport::query()->count());
        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
    }

    public function test_factory_does_not_create_a_file_and_user_deletion_cascades_metadata(): void
    {
        Storage::fake('statement-imports');
        $statementImport = StatementImport::factory()->create();

        Storage::disk('statement-imports')->assertDirectoryEmpty('/');
        $statementImport->user->delete();

        $this->assertDatabaseMissing('statement_imports', ['id' => $statementImport->id]);
    }

    private function validPdf(string $filename = 'statement.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF",
        )->mimeType('application/pdf');
    }
}
