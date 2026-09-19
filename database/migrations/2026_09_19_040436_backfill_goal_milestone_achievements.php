<?php

use Brick\Math\BigDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $backfilledAt = now();

        DB::table('goals')->select(['id', 'target_amount'])->orderBy('id')->chunkById(
            100,
            function (Collection $goals) use ($backfilledAt): void {
                foreach ($goals as $goal) {
                    $allocated = BigDecimal::of('0.00');

                    foreach (DB::table('goal_account_allocations')->where('goal_id', $goal->id)->pluck('amount') as $amount) {
                        $allocated = $allocated->plus((string) $amount);
                    }

                    foreach ([25, 50, 75, 100] as $milestone) {
                        $reached = $allocated->multipliedBy(100)
                            ->isGreaterThanOrEqualTo(BigDecimal::of((string) $goal->target_amount)->multipliedBy($milestone));

                        if ($reached) {
                            DB::table('goal_milestone_achievements')->insertOrIgnore([
                                'goal_id' => $goal->id,
                                'milestone_percentage' => $milestone,
                                'achieved_at' => $backfilledAt,
                                'created_at' => $backfilledAt,
                                'updated_at' => $backfilledAt,
                            ]);
                        }
                    }
                }
            },
            column: 'id',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backfilled dates are deployment timestamps, not invented historical achievement dates.
    }
};
