<?php

namespace Tests\Feature;

use App\Actions\SaveGoalAccountAllocation;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Runs against migrated MariaDB with every fixture rolled back. */
class SharedGoalsMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify membership constraints and allocation locking.');
        }
    }

    public function test_membership_unique_pair_and_foreign_keys_are_enforced(): void
    {
        $goal = Goal::factory()->create();
        $member = User::factory()->create();
        GoalMembership::factory()->for($goal)->for($member)->create();

        $this->expectException(QueryException::class);
        GoalMembership::factory()->for($goal)->for($member)->create();
    }

    public function test_membership_cascades_when_member_or_goal_is_deleted(): void
    {
        $goal = Goal::factory()->create();
        $member = User::factory()->create();
        $membership = GoalMembership::factory()->for($goal)->for($member)->create();

        $member->delete();
        $this->assertModelMissing($membership);

        $secondMember = User::factory()->create();
        $secondMembership = GoalMembership::factory()->for($goal)->for($secondMember)->create();
        $goal->delete();
        $this->assertModelMissing($secondMembership);
    }

    public function test_shared_allocation_authoritative_save_locks_account_then_goal(): void
    {
        $goal = Goal::factory()->create(['target_amount' => '100.00']);
        $member = User::factory()->create();
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $account = Account::factory()->for($member)->create(['current_balance' => '100.00']);
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        app(SaveGoalAccountAllocation::class)->handle($member, $goal->id, $account->id, '100.00');

        $accountLock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'from `accounts`') && str_contains($sql, 'for update'));
        $goalLock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'from `goals`') && str_contains($sql, 'for update'));
        $this->assertIsInt($accountLock);
        $this->assertIsInt($goalLock);
        $this->assertLessThan($goalLock, $accountLock);
        $this->assertSame('100.00', $goal->fresh()->allocatedAmount());
    }
}
