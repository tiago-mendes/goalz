<?php

namespace App\Models;

use App\AccountType;
use Brick\Math\BigDecimal;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** @return HasMany<GoalAccountAllocation, $this> */
    public function goalAccountAllocations(): HasMany
    {
        return $this->hasMany(GoalAccountAllocation::class);
    }

    public function allocatedAmount(): string
    {
        $total = BigDecimal::of('0.00');
        $allocations = $this->relationLoaded('goalAccountAllocations')
            ? $this->goalAccountAllocations
            : $this->goalAccountAllocations()->get(['amount']);

        foreach ($allocations as $allocation) {
            $total = $total->plus($allocation->amount);
        }

        return (string) $total->toScale(2);
    }

    public function availableAmount(): string
    {
        return (string) BigDecimal::of($this->current_balance)->minus($this->allocatedAmount())->toScale(2);
    }

    public function overallocatedAmount(): string
    {
        $available = BigDecimal::of($this->availableAmount());

        return $available->isNegative() ? (string) $available->negated()->toScale(2) : '0.00';
    }
}
