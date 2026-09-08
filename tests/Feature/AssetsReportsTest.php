<?php

namespace Tests\Feature;

use App\AccountType;
use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AssetsReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assets_report_defaults_to_the_last_six_calendar_months_and_preserves_query_parameters(): void
    {
        $this->travelTo('2026-09-07');
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::reports.assets')
            ->assertSet('from', '2026-04')
            ->assertSet('to', '2026-09')
            ->assertSet('selectedFrom', '2026-04')
            ->assertSet('selectedTo', '2026-09');

        Livewire::withQueryParams(['from' => '2025-01', 'to' => '2025-02'])
            ->test('pages::reports.assets')
            ->assertSet('from', '2025-01')
            ->assertSet('to', '2025-02')
            ->assertSet('selectedFrom', '2025-01')
            ->assertSet('selectedTo', '2025-02');
    }

    public function test_assets_report_summarizes_active_owned_assets_with_exact_amounts(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $other = User::factory()->create();
        $account = Account::factory()->for($user)->create(['current_balance' => '1234567890.45']);
        Account::factory()->for($user)->inactive()->create(['current_balance' => '999.99']);
        Account::factory()->for($other)->create(['current_balance' => '8888888888888.88']);
        $goal = Goal::factory()->for($user)->create(['target_amount' => '200.00']);
        GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '123.45']);
        $this->actingAs($user);

        $page = Livewire::test('pages::reports.assets');
        $report = $page->get('report');

        $this->assertSame('1234567890.45', $report['totalAssets']);
        $this->assertSame('123.45', $report['allocatedAssets']);
        $this->assertSame('1234567767.00', $report['freeAssets']);
        $this->assertCount(1, $report['distribution']);
        $this->assertSame('Checking', $report['distribution'][0]['type']);
        $this->assertSame('1234567890.45', $report['distribution'][0]['balance']);
        $page->assertSeeText(['Total Assets', 'Total Allocated', 'Total Available', 'R$ 1234567890.45', 'R$ 123.45', 'R$ 1234567767.00']);
    }

    public function test_assets_by_type_includes_every_type_and_handles_zero_total_percentages(): void
    {
        $user = User::factory()->create();
        foreach (AccountType::cases() as $type) {
            Account::factory()->for($user)->create(['type' => $type, 'current_balance' => '0.00']);
        }
        $this->actingAs($user);

        $report = Livewire::test('pages::reports.assets')->get('report');

        $this->assertCount(count(AccountType::cases()), $report['accountTypes']);
        $this->assertSame(array_fill(0, count(AccountType::cases()), '0.00'), array_column($report['accountTypes'], 'amount'));
        $this->assertSame(array_fill(0, count(AccountType::cases()), '0.0'), array_column($report['accountTypes'], 'percentage'));
    }

    public function test_assets_by_type_and_distribution_exclude_inactive_and_foreign_accounts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Account::factory()->for($user)->create(['name' => 'Savings', 'type' => AccountType::Savings, 'current_balance' => '200.00']);
        Account::factory()->for($user)->inactive()->create(['name' => 'Old Cash', 'type' => AccountType::Cash, 'current_balance' => '300.00']);
        Account::factory()->for($other)->create(['name' => 'Private', 'current_balance' => '999.00']);
        $this->actingAs($user);

        $report = Livewire::test('pages::reports.assets')->get('report');

        $this->assertSame('200.00', $report['totalAssets']);
        $this->assertSame('200.00', $report['accountTypes'][1]['amount']);
        $this->assertSame('100.0', $report['accountTypes'][1]['percentage']);
        $this->assertSame(['Savings'], array_column($report['distribution'], 'name'));
    }

    public function test_allocated_free_and_overallocated_assets_reuse_account_semantics(): void
    {
        $user = User::factory()->create();
        $first = Account::factory()->for($user)->create(['name' => 'First', 'current_balance' => '100.00']);
        $second = Account::factory()->for($user)->create(['name' => 'Second', 'current_balance' => '200.00']);
        $firstGoal = Goal::factory()->for($user)->create(['target_amount' => '150.00']);
        $secondGoal = Goal::factory()->for($user)->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($firstGoal)->for($first)->create(['amount' => '150.00']);
        GoalAccountAllocation::factory()->for($secondGoal)->for($second)->create(['amount' => '50.00']);
        $this->actingAs($user);

        $report = Livewire::test('pages::reports.assets')->get('report');

        $this->assertSame('300.00', $report['totalAssets']);
        $this->assertSame('200.00', $report['allocatedAssets']);
        $this->assertSame('100.00', $report['freeAssets']);
        $this->assertSame('50.00', $report['overallocatedAssets']);
        $this->assertSame(1, $report['overallocatedAccounts']);
    }

    public function test_balance_evolution_is_selected_account_owned_and_period_filtered(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $active = Account::factory()->for($user)->create(['name' => 'Active']);
        $inactive = Account::factory()->for($user)->inactive()->create(['name' => 'Inactive']);
        $foreign = Account::factory()->for($other)->create(['name' => 'Private']);
        $first = AccountBalanceSnapshot::factory()->for($active)->create(['balance' => '100.00', 'recorded_at' => '2026-04-01 08:00:00']);
        $second = AccountBalanceSnapshot::factory()->for($active)->create(['balance' => '125.00', 'recorded_at' => '2026-04-01 08:00:00']);
        AccountBalanceSnapshot::factory()->for($active)->create(['balance' => '200.00', 'recorded_at' => '2026-03-31 23:59:59']);
        AccountBalanceSnapshot::factory()->for($inactive)->create(['balance' => '300.00', 'recorded_at' => '2026-04-02 08:00:00']);
        AccountBalanceSnapshot::factory()->for($foreign)->create(['balance' => '999.00', 'recorded_at' => '2026-04-02 08:00:00']);
        $this->actingAs($user);

        $page = Livewire::test('pages::reports.assets')
            ->set('from', '2026-04')
            ->set('to', '2026-04')
            ->call('applyPeriod');
        $report = $page->get('report');

        $this->assertSame($active->id, $page->get('accountId'));
        $this->assertSame([$first->id, $second->id], array_column($report['accountEvolution'], 'key'));
        $this->assertSame(['100.00', '125.00'], array_column($report['accountEvolution'], 'balance'));

        $page->set('accountId', $inactive->id);
        $this->assertSame(['300.00'], array_column($page->get('report')['accountEvolution'], 'balance'));

        $page->set('accountId', $foreign->id)
            ->assertHasErrors('accountId')
            ->assertSet('accountId', $active->id);
    }

    public function test_assets_report_falls_back_from_a_stale_account_and_handles_no_accounts(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::withQueryParams(['accountId' => 999999])
            ->test('pages::reports.assets')
            ->assertHasErrors('accountId')
            ->assertSet('accountId', $account->id);

        $emptyUser = User::factory()->create();
        $this->actingAs($emptyUser);
        Livewire::test('pages::reports.assets')
            ->assertSet('accountId', null)
            ->assertSeeText('No accounts are available for balance evolution.');
    }

    public function test_admin_only_sees_owned_assets(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create();
        Account::factory()->for($other)->create(['name' => 'Private Account', 'current_balance' => '999.00']);
        $this->actingAs($admin);

        Livewire::test('pages::reports.assets')
            ->assertSet('accountId', null)
            ->assertSet('report.totalAssets', '0.00')
            ->assertDontSeeText('Private Account');
    }

    public function test_opening_and_updating_assets_report_is_read_only(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['current_balance' => '100.00']);
        $goal = Goal::factory()->for($user)->create();
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '25.00']);
        $snapshot = AccountBalanceSnapshot::factory()->for($account)->create(['balance' => '100.00']);
        $beforeAccount = $account->fresh();
        $beforeAllocation = $allocation->fresh()->only(['amount', 'goal_id', 'account_id']);
        $beforeSnapshot = $snapshot->fresh();
        $this->actingAs($user);

        Livewire::test('pages::reports.assets')
            ->set('from', '2026-01')
            ->set('to', '2026-06')
            ->call('applyPeriod');

        $this->assertSame($beforeAccount->current_balance, $account->fresh()->current_balance);
        $this->assertTrue($beforeAccount->balance_updated_at->equalTo($account->fresh()->balance_updated_at));
        $this->assertSame($beforeAllocation, $allocation->fresh()->only(['amount', 'goal_id', 'account_id']));
        $this->assertSame($beforeSnapshot->balance, $snapshot->fresh()->balance);
        $this->assertTrue($beforeSnapshot->recorded_at->equalTo($snapshot->fresh()->recorded_at));
        $this->assertSame($beforeSnapshot->account_id, $snapshot->fresh()->account_id);
        $this->assertDatabaseCount('account_balance_snapshots', 1);
        $this->assertDatabaseCount('goal_account_allocations', 1);
    }
}
