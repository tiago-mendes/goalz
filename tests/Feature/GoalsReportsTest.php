<?php

namespace Tests\Feature;

use App\AccountType;
use App\GoalStatus;
use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\User;
use App\Reports\GoalsReport;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GoalsReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_progress_includes_all_owned_statuses_with_exact_current_values(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $active = Goal::factory()->for($user)->create(['name' => 'Active Goal', 'target_amount' => '100.01']);
        $paused = Goal::factory()->for($user)->paused()->create(['name' => 'Paused Goal', 'target_amount' => '300.00']);
        $completed = Goal::factory()->for($user)->completed()->create(['name' => 'Completed Goal', 'target_amount' => '80.00']);
        $account = Account::factory()->for($user)->create(['current_balance' => '500.00']);
        GoalAccountAllocation::factory()->for($active)->for($account)->create(['amount' => '40.00']);
        GoalAccountAllocation::factory()->for($active)->for(Account::factory()->for($user)->create())->create(['amount' => '60.01']);
        GoalAccountAllocation::factory()->for($paused)->for(Account::factory()->for($user)->create())->create(['amount' => '0.01']);
        $this->actingAs($user);

        $page = Livewire::test('pages::reports.goals');
        $report = $page->get('report');

        $this->assertSame(['Active Goal', 'Paused Goal', 'Completed Goal'], array_column($report['goals'], 'name'));
        $this->assertSame('100.01', $report['goals'][0]['target']);
        $this->assertSame('100.01', $report['goals'][0]['allocated']);
        $this->assertSame('0.00', $report['goals'][0]['remaining']);
        $this->assertSame('100.0', $report['goals'][0]['progress']);
        $this->assertSame('Active', $report['goals'][0]['status']);
        $this->assertSame('0.01', $report['goals'][1]['allocated']);
        $this->assertSame('Paused', $report['goals'][1]['status']);
        $this->assertSame('Completed', $report['goals'][2]['status']);
    }

    public function test_goal_progress_handles_zero_allocation_and_overfunding_without_changing_status(): void
    {
        $user = User::factory()->create();
        $zero = Goal::factory()->for($user)->create(['name' => 'Zero', 'target_amount' => '100.00']);
        $overfunded = Goal::factory()->for($user)->create(['name' => 'Overfunded', 'target_amount' => '80.00']);
        $account = Account::factory()->for($user)->create(['current_balance' => '200.00']);
        GoalAccountAllocation::factory()->for($overfunded)->for($account)->create(['amount' => '100.01']);
        $this->actingAs($user);

        $report = app(GoalsReport::class)->handle($user, $zero->id);
        $zeroProgress = collect($report['goals'])->firstWhere('key', $zero->id);
        $overfundedProgress = collect($report['goals'])->firstWhere('key', $overfunded->id);

        $this->assertSame('0.00', $zeroProgress['allocated']);
        $this->assertSame('0.0', $zeroProgress['progress']);
        $this->assertSame('100.01', $overfundedProgress['allocated']);
        $this->assertSame('-20.01', $overfundedProgress['remaining']);
        $this->assertSame('125.0', $overfundedProgress['progress']);
        $this->assertSame('20.01', $overfundedProgress['overfunded']);
        $this->assertSame(GoalStatus::Active, $overfunded->refresh()->status);
    }

    public function test_funding_sources_use_current_allocations_and_exact_shares(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $goal = Goal::factory()->for($user)->create(['target_amount' => '40000.00']);
        $savings = Account::factory()->for($user)->create(['name' => 'Itaú Savings', 'type' => AccountType::Savings]);
        $investment = Account::factory()->for($user)->create(['name' => 'XP Investment', 'type' => AccountType::Investment]);
        GoalAccountAllocation::factory()->for($goal)->for($savings)->create(['amount' => '10000.00']);
        GoalAccountAllocation::factory()->for($goal)->for($investment)->create(['amount' => '5000.00']);
        $this->actingAs($user);

        $page = Livewire::test('pages::reports.goals');
        $report = $page->get('report');

        $this->assertSame('15000.00', $report['fundingTotal']);
        $this->assertSame(['Itaú Savings', 'XP Investment'], array_column($report['fundingSources'], 'name'));
        $this->assertSame(['66.7', '33.3'], array_column($report['fundingSources'], 'share'));
        $this->assertSame(['Savings', 'Investment'], array_column($report['fundingSources'], 'type'));
    }

    public function test_inactive_account_allocations_remain_visible_and_zero_funding_is_safe(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create();
        $account = Account::factory()->for($user)->inactive()->create(['name' => 'Closed Account']);
        GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '25.00']);
        $emptyGoal = Goal::factory()->for($user)->create(['name' => 'Empty Goal']);
        $this->actingAs($user);

        $page = Livewire::test('pages::reports.goals')->set('goalId', $goal->id);
        $sources = $page->get('report')['fundingSources'];
        $this->assertSame('Closed Account', $sources[0]['name']);
        $this->assertFalse($sources[0]['isActive']);
        $this->assertSame('25.00', $sources[0]['allocated']);

        $page->set('goalId', $emptyGoal->id);
        $this->assertSame([], $page->get('report')['fundingSources']);
        $this->assertSame('0.00', $page->get('report')['fundingTotal']);
    }

    public function test_goal_selector_defaults_deterministically_preserves_owned_selection_and_rejects_foreign_or_stale_goals(): void
    {
        $user = User::factory()->create();
        $active = Goal::factory()->for($user)->create(['name' => 'Active']);
        $paused = Goal::factory()->for($user)->paused()->create(['name' => 'Paused']);
        $foreign = Goal::factory()->create(['name' => 'Private']);
        $this->actingAs($user);

        $page = Livewire::withQueryParams(['goalId' => $paused->id])->test('pages::reports.goals');
        $page->assertSet('goalId', $paused->id)->set('goalId', $active->id)->assertSet('goalId', $active->id);
        $page->set('goalId', $foreign->id)->assertHasErrors('goalId')->assertSet('goalId', $active->id);
        $page->set('goalId', 999999)->assertHasErrors('goalId')->assertSet('goalId', $active->id);

        $emptyUser = User::factory()->create();
        $this->actingAs($emptyUser);
        Livewire::test('pages::reports.goals')->assertSet('goalId', null)->assertSeeText('No goals yet.');
    }

    public function test_goal_reports_exclude_foreign_goals_and_admin_has_no_financial_bypass(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create();
        Goal::factory()->for($other)->create(['name' => 'Private Goal']);
        $this->actingAs($admin);

        Livewire::test('pages::reports.goals')
            ->assertSet('goalId', null)
            ->assertDontSeeText('Private Goal')
            ->assertSeeText('No goals yet.');
    }

    public function test_goal_reports_exclude_a_foreign_account_allocation_from_an_owned_goal(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $goal = Goal::factory()->for($user)->create();
        $foreignAccount = Account::factory()->for($other)->create(['name' => 'Private Account']);
        GoalAccountAllocation::factory()->for($goal)->for($foreignAccount)->create(['amount' => '999.00']);
        $this->actingAs($user);

        $page = Livewire::test('pages::reports.goals');
        $report = $page->get('report');

        $this->assertSame('0.00', $report['goals'][0]['allocated']);
        $this->assertSame([], $report['fundingSources']);
        $this->assertSame('0.00', $report['fundingTotal']);
        $page->assertDontSeeText('Private Account');
    }

    public function test_opening_and_updating_goals_report_is_read_only(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create();
        $account = Account::factory()->for($user)->create();
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '25.00']);
        $snapshot = AccountBalanceSnapshot::factory()->for($account)->create(['balance' => '100.00']);
        $beforeGoal = $goal->fresh()->only(['target_amount', 'status']);
        $beforeAccount = $account->fresh()->only(['current_balance', 'balance_updated_at']);
        $beforeAllocation = $allocation->fresh()->only(['amount', 'goal_id', 'account_id']);
        $this->actingAs($user);

        Livewire::test('pages::reports.goals')->set('goalId', $goal->id);

        $this->assertSame($beforeGoal, $goal->fresh()->only(['target_amount', 'status']));
        $afterAccount = $account->fresh();
        $this->assertSame($beforeAccount['current_balance'], $afterAccount->current_balance);
        $this->assertTrue($beforeAccount['balance_updated_at']->equalTo($afterAccount->balance_updated_at));
        $this->assertSame($beforeAllocation, $allocation->fresh()->only(['amount', 'goal_id', 'account_id']));
        $this->assertSame(1, AccountBalanceSnapshot::query()->count());
        $this->assertSame(1, GoalAccountAllocation::query()->count());
        $this->assertModelExists($snapshot);
    }

    public function test_goals_report_chart_payload_uses_numeric_values_only_at_the_chart_boundary(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create(['name' => 'Trip', 'target_amount' => '100.01']);
        $account = Account::factory()->for($user)->create();
        GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '33.34']);
        $this->actingAs($user);

        $chartData = Livewire::test('pages::reports.goals')->get('chartData');

        $this->assertSame(['Trip'], $chartData['progress']['labels']);
        $this->assertSame([100.01], $chartData['progress']['targets']);
        $this->assertSame([33.34], $chartData['progress']['allocated']);
        $this->assertSame([33.34], $chartData['fundingSources']['amounts']);
    }
}
