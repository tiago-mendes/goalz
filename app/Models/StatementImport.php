<?php

namespace App\Models;

use App\StatementDocumentType;
use App\StatementImportStatus;
use App\StatementInstitution;
use Database\Factories\StatementImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $original_filename
 * @property string $storage_disk
 * @property string $storage_path
 * @property StatementImportStatus $status
 * @property StatementDocumentType|null $document_type
 * @property StatementInstitution|null $institution
 * @property int|null $account_id
 * @property int|null $credit_card_id
 * @property int|null $bill_due_year
 * @property int|null $bill_due_month
 * @property string|null $file_sha256
 * @property string|null $failure_message
 * @property Carbon|null $imported_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Account|null $account
 * @property-read CreditCard|null $creditCard
 */
#[Fillable(['original_filename', 'failure_message'])]
class StatementImport extends Model
{
    /** @use HasFactory<StatementImportFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatementImportStatus::class,
            'document_type' => StatementDocumentType::class,
            'institution' => StatementInstitution::class,
            'bill_due_year' => 'integer',
            'bill_due_month' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<CreditCard, $this> */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function hasImportContext(): bool
    {
        if ($this->document_type === null || $this->institution === null) {
            return false;
        }

        return match ($this->document_type) {
            StatementDocumentType::BankStatement => $this->account_id !== null
                && $this->credit_card_id === null
                && $this->bill_due_year === null
                && $this->bill_due_month === null,
            StatementDocumentType::CreditCardBill => $this->account_id === null
                && $this->credit_card_id !== null
                && $this->bill_due_year !== null
                && $this->bill_due_year >= 1000
                && $this->bill_due_year <= 9999
                && $this->bill_due_month !== null
                && $this->bill_due_month >= 1
                && $this->bill_due_month <= 12,
        };
    }
}
