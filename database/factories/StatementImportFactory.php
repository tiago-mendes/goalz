<?php

namespace Database\Factories;

use App\Models\StatementImport;
use App\Models\User;
use App\StatementImportStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatementImport> */
class StatementImportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_filename' => 'statement.pdf',
            'storage_disk' => 'statement-imports',
            'storage_path' => fake()->randomNumber().'/'.fake()->uuid().'.pdf',
            'status' => StatementImportStatus::Uploaded,
            'document_type' => null,
            'institution' => null,
            'account_id' => null,
            'credit_card_id' => null,
            'bill_due_year' => null,
            'bill_due_month' => null,
            'file_sha256' => null,
            'failure_message' => null,
            'imported_at' => null,
        ];
    }
}
