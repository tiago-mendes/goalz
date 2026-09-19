<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\GoalMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GoalFundingPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_plan_uses_calendar_months_and_is_displayed_on_index_and_manage_views(): void
    {
        $this->travelTo('2026-09-19');
        $user = User::factory()->create(['currency' => 'BRL']);
        $goal = Goal::factory()->for($user)->create([
            'target_amount' => '20000.00',
            'target_date' => '2026-12-05',
        ]);
        GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($user))->create(['amount' => '11000.00']);
        $this->actingAs($user);

        $plan = $goal->fresh()->fundingPlan();

        $this->assertSame('9000.00', $plan->remainingAmount);
        $this->assertSame(3, $plan->monthsRemaining);
        $this->assertSame('3000.00', $plan->requiredPerMonth);
        $this->assertSame('active', $plan->deadlineState);
        Livewire::test('pages::goals.index')->assertSeeText(['R$ 11000.00', 'R$ 20000.00', 'R$ 3000.00', '/month needed', '3 months remaining']);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSeeText(['Target date', 'Dec 5, 2026', 'Time remaining', '3 months', 'Needed per month', 'R$ 3000.00']);
    }

    public function test_exact_decimal_rounding_and_deadline_states_are_derived_without_monthly_amount_for_invalid_periods(): void
    {
        $this->travelTo('2026-09-19');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $active = Goal::factory()->for($user)->create(['target_amount' => '10000.00', 'target_date' => '2026-12-19']);
        GoalAccountAllocation::factory()->for($active)->for($account)->create(['amount' => '0.00']);
        $this->assertSame('3333.33', $active->fundingPlan()->requiredPerMonth);

        $currentMonth = Goal::factory()->for($user)->create(['target_amount' => '2000.00', 'target_date' => '2026-09-30']);
        $this->assertSame(1, $currentMonth->fundingPlan()->monthsRemaining);
        $this->assertSame('2000.00', $currentMonth->fundingPlan()->requiredPerMonth);

        $dueToday = Goal::factory()->for($user)->create(['target_amount' => '2000.00', 'target_date' => '2026-09-19']);
        $this->assertSame('due_today', $dueToday->fundingPlan()->deadlineState);
        $this->assertNull($dueToday->fundingPlan()->requiredPerMonth);

        $overdue = Goal::factory()->for($user)->create(['target_amount' => '2000.00', 'target_date' => '2026-08-31']);
        $this->assertSame('overdue', $overdue->fundingPlan()->deadlineState);
        $this->assertSame('2000.00', $overdue->fundingPlan()->remainingAmount);
        $this->assertNull($overdue->fundingPlan()->requiredPerMonth);
    }

    public function test_funded_and_undated_goals_do_not_show_a_required_monthly_amount(): void
    {
        $this->travelTo('2026-09-19');
        $user = User::factory()->create(['currency' => 'BRL']);
        $account = Account::factory()->for($user)->create();
        $funded = Goal::factory()->for($user)->create(['name' => 'Funded Goal', 'target_amount' => '20000.00', 'target_date' => '2026-12-19']);
        GoalAccountAllocation::factory()->for($funded)->for($account)->create(['amount' => '22000.00']);
        $undated = Goal::factory()->for($user)->create(['name' => 'Undated Goal', 'target_amount' => '20000.00']);
        $this->actingAs($user);

        $this->assertSame('funded', $funded->fundingPlan()->deadlineState);
        $this->assertSame('0.00', $funded->fundingPlan()->remainingAmount);
        $this->assertNull($funded->fundingPlan()->requiredPerMonth);
        $this->assertSame('no_target_date', $undated->fundingPlan()->deadlineState);
        $this->assertNull($undated->fundingPlan()->requiredPerMonth);

        Livewire::test('pages::goals.index')
            ->assertSeeText('Funding target reached')
            ->assertDontSeeText('R$ 0.00/month needed');
    }

    public function test_shared_owner_and_member_see_the_same_combined_goal_level_plan(): void
    {
        $this->travelTo('2026-09-19');
        $owner = User::factory()->create(['currency' => 'BRL']);
        $member = User::factory()->create(['currency' => 'BRL']);
        $goal = Goal::factory()->for($owner)->create([
            'name' => 'Italy Trip',
            'target_amount' => '20000.00',
            'target_date' => '2026-12-19',
        ]);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($owner))->create(['amount' => '7000.00']);
        GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($member))->create(['amount' => '4000.00']);

        foreach ([$owner, $member] as $user) {
            $this->actingAs($user);
            Livewire::test('pages::goals.index')
                ->assertSeeText(['Italy Trip', 'R$ 11000.00', 'R$ 20000.00', 'R$ 3000.00', '/month needed', '3 months remaining'])
                ->assertDontSeeText('Account');
        }

        $this->assertSame('9000.00', $goal->fresh()->fundingPlan()->remainingAmount);
    }
}
