<?php

namespace Tests\Feature;

use App\Actions\SaveGoalAccountAllocation;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Runs against migrated MariaDB with every fixture rolled back. */
class GoalAccountAllocationsMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify allocation decimal storage and constraints.');
        }
    }

    public function test_maximum_decimal_round_trips_exactly(): void
    {
        $goal = Goal::factory()->create(['target_amount' => '9999999999999.99']);
        $account = Account::factory()->for($goal->user)->create(['current_balance' => '9999999999999.99']);

        $allocation = app(SaveGoalAccountAllocation::class)->handle($goal->user, $goal->id, $account->id, '9999999999999.99');

        $this->assertSame('9999999999999.99', $allocation->refresh()->getRawOriginal('amount'));
    }

    public function test_database_rejects_non_positive_amount(): void
    {
        $this->expectException(QueryException::class);
        GoalAccountAllocation::factory()->create(['amount' => '0.00']);
    }

    public function test_database_enforces_unique_goal_account_pair(): void
    {
        $goal = Goal::factory()->create();
        $account = Account::factory()->for($goal->user)->create();
        GoalAccountAllocation::factory()->for($goal)->for($account)->create();

        $this->expectException(QueryException::class);
        GoalAccountAllocation::factory()->for($goal)->for($account)->create();
    }

    public function test_foreign_keys_reject_missing_parents_and_cascade_on_user_deletion(): void
    {
        $goal = Goal::factory()->create();
        $account = Account::factory()->for($goal->user)->create();
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create();

        $goal->user->delete();

        $this->assertModelMissing($allocation);

        $this->expectException(QueryException::class);
        DB::table('goal_account_allocations')->insert(['goal_id' => 0, 'account_id' => 0, 'amount' => '1.00']);
    }

    public function test_authoritative_save_locks_account_and_goal_inside_transaction(): void
    {
        $goal = Goal::factory()->create(['target_amount' => '100.00']);
        $account = Account::factory()->for($goal->user)->create(['current_balance' => '100.00']);
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        app(SaveGoalAccountAllocation::class)->handle($goal->user, $goal->id, $account->id, '100.00');

        $this->assertTrue(collect($statements)->contains(fn (string $sql): bool => str_contains($sql, 'from `accounts`') && str_contains($sql, 'for update')));
        $this->assertTrue(collect($statements)->contains(fn (string $sql): bool => str_contains($sql, 'from `goals`') && str_contains($sql, 'for update')));
    }
}
