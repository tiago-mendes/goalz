<?php

namespace Tests\Feature;

use App\Actions\SaveGoalAccountAllocation;
use App\GoalMilestone;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalMilestoneAchievement;
use App\Models\GoalMilestoneNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Runs against migrated MariaDB with every fixture rolled back. */
class GoalRewardsMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify milestone uniqueness and allocation locking.');
        }
    }

    public function test_database_prevents_duplicate_goal_milestone_achievement(): void
    {
        $goal = Goal::factory()->create();
        GoalMilestoneAchievement::factory()->for($goal)->create([
            'milestone_percentage' => GoalMilestone::Fifty,
        ]);

        $this->expectException(QueryException::class);
        GoalMilestoneAchievement::factory()->for($goal)->create([
            'milestone_percentage' => GoalMilestone::Fifty,
        ]);
    }

    public function test_database_prevents_duplicate_participant_notification(): void
    {
        $achievement = GoalMilestoneAchievement::factory()->create();
        GoalMilestoneNotification::factory()->for($achievement, 'achievement')->for($achievement->goal->user)->create();

        $this->expectException(QueryException::class);
        GoalMilestoneNotification::factory()->for($achievement, 'achievement')->for($achievement->goal->user)->create();
    }

    public function test_allocation_increase_locks_goal_before_evaluating_milestones(): void
    {
        $goal = Goal::factory()->create(['target_amount' => '100.00']);
        $account = Account::factory()->for($goal->user)->create(['current_balance' => '100.00']);
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        app(SaveGoalAccountAllocation::class)->handle($goal->user, $goal->id, $account->id, '50.00');

        $accountLock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'accounts') && str_contains($sql, 'for update'));
        $goalLocks = collect($statements)->filter(fn (string $sql): bool => str_contains($sql, 'goals') && str_contains($sql, 'for update'))->keys();
        $this->assertIsInt($accountLock);
        $this->assertNotEmpty($goalLocks);
        $this->assertLessThan($goalLocks->first(), $accountLock);
        $this->assertSame(
            [25, 50],
            GoalMilestoneAchievement::query()->whereBelongsTo($goal)->orderBy('milestone_percentage')->get()
                ->map(fn (GoalMilestoneAchievement $achievement): int => $achievement->milestone_percentage->value)->all(),
        );
    }
}
