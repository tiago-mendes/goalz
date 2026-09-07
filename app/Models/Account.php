<?php

namespace App\Models;

use App\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A manually maintained current asset balance, not a transaction ledger.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property AccountType $type
 * @property string $current_balance
 * @property Carbon $balance_updated_at
 * @property bool $is_active
 */
#[Fillable(['name', 'type', 'current_balance'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'current_balance' => 'decimal:2',
            'balance_updated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
