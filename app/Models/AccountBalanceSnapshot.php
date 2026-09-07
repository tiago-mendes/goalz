<?php

namespace App\Models;

use Database\Factories\AccountBalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An immutable observation of an Account's manually reported current balance.
 *
 * @property int $id
 * @property int $account_id
 * @property string $balance
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 */
class AccountBalanceSnapshot extends Model
{
    /** @use HasFactory<AccountBalanceSnapshotFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Account balance snapshots are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Account balance snapshots are immutable.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
