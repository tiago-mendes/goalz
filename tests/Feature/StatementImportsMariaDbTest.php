<?php

namespace Tests\Feature;

use App\Models\StatementImport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
}
