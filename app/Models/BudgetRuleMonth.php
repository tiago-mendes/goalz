<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $month */
#[Fillable(['budget_rule_id', 'month'])]
class BudgetRuleMonth extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['month' => 'date'];
    }

    /** @return BelongsTo<BudgetRule, $this> */
    public function budgetRule(): BelongsTo
    {
        return $this->belongsTo(BudgetRule::class);
    }
}
