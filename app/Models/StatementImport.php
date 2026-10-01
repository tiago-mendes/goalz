<?php

namespace App\Models;

use App\StatementImportStatus;
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
 * @property string|null $source
 * @property string|null $failure_message
 * @property Carbon|null $imported_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['original_filename', 'source', 'failure_message'])]
class StatementImport extends Model
{
    /** @use HasFactory<StatementImportFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatementImportStatus::class,
            'imported_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
