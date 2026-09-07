<?php

namespace App\Models;

use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'icon', 'color', 'is_active'])]
class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    public const array ICONS = [
        'shopping-cart' => 'Food', 'truck' => 'Transport', 'home' => 'Housing',
        'heart' => 'Health', 'puzzle-piece' => 'Leisure', 'academic-cap' => 'Education',
        'arrow-path' => 'Subscriptions', 'tag' => 'Other',
        'shopping-bag' => 'Shopping', 'cake' => 'Celebrations', 'map-pin' => 'Destinations',
        'building-office' => 'Office', 'wrench-screwdriver' => 'Maintenance', 'bolt' => 'Electricity',
        'wifi' => 'Internet', 'phone' => 'Phone', 'book-open' => 'Books',
        'film' => 'Movies', 'musical-note' => 'Music', 'ticket' => 'Events',
        'paper-airplane' => 'Travel', 'credit-card' => 'Card payments', 'banknotes' => 'Cash',
        'receipt-percent' => 'Bills', 'briefcase' => 'Work', 'gift' => 'Gifts',
        'users' => 'Family', 'shield-check' => 'Insurance', 'sparkles' => 'Personal care',
    ];

    public const string DEFAULT_ICON = 'tag';

    public const string DEFAULT_COLOR = '#64748B';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<FixedExpense, $this> */
    public function fixedExpenses(): HasMany
    {
        return $this->hasMany(FixedExpense::class);
    }

    public function safeIcon(): string
    {
        return array_key_exists($this->icon, self::ICONS) ? $this->icon : self::DEFAULT_ICON;
    }

    public function safeColor(): string
    {
        return preg_match('/\A#[0-9A-Fa-f]{6}\z/', $this->color) === 1 ? $this->color : self::DEFAULT_COLOR;
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
