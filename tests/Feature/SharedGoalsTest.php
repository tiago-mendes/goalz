<?php

namespace Tests\Feature;

use App\Actions\EndGoalMembership;
use App\Actions\SaveGoalAccountAllocation;
use App\GoalMembershipStatus;
use App\GoalStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\GoalMembership;
use App\Models\User;
use App\Reports\AssetsReport;
use App\Reports\GoalsReport;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SharedGoalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_invites_an_active_registered_user_and_declined_row_is_reused(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'Member@Example.test']);
        $goal = Goal::factory()->for($owner)->create();
        $this->actingAs($owner);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->set('inviteEmail', ' member@example.test ')->call('invite')->assertHasNoErrors();

        $membership = GoalMembership::query()->sole();
        $this->assertSame($goal->id, $membership->goal_id);
        $this->assertSame($member->id, $membership->user_id);
        $this->assertSame($owner->id, $membership->invited_by_user_id);
        $this->assertSame(GoalMembershipStatus::Pending, $membership->status);
        $this->assertNull($membership->responded_at);

        $membership->update(['status' => GoalMembershipStatus::Declined, 'responded_at' => now()]);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->set('inviteEmail', 'member@example.test')->call('invite')->assertHasNoErrors();

        $this->assertSame(1, GoalMembership::query()->count());
        $this->assertSame($membership->id, GoalMembership::query()->sole()->id);
        $this->assertSame(GoalMembershipStatus::Pending, $membership->refresh()->status);
        $this->assertNull($membership->responded_at);
    }

    public function test_invitation_rejects_self_inactive_unknown_pending_and_accepted_users(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $inactive = User::factory()->create(['email' => 'inactive@example.test', 'is_active' => false]);
        $member = User::factory()->create(['email' => 'member@example.test']);
        $goal = Goal::factory()->for($owner)->create();
        GoalMembership::factory()->for($goal)->for($member)->create();
        $this->actingAs($owner);

        foreach (['owner@example.test', 'inactive@example.test', 'missing@example.test', 'member@example.test'] as $email) {
            Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
                ->set('inviteEmail', $email)->call('invite')->assertHasErrors('inviteEmail');
        }

        $membership = GoalMembership::query()->whereBelongsTo($member)->sole();
        $membership->update(['status' => GoalMembershipStatus::Accepted, 'responded_at' => now()]);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->set('inviteEmail', $member->email)->call('invite')->assertHasErrors('inviteEmail');

        $this->assertSame(1, GoalMembership::query()->count());
        $this->assertModelExists($inactive);
    }

    public function test_only_owner_can_invite_or_manage_goal_metadata_without_admin_bypass(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $goal = Goal::factory()->for($owner)->create();
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('invite', $goal));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $goal));
        $this->assertSame(404, Gate::forUser($member)->inspect('invite', $goal)->status());
        $this->assertSame(404, Gate::forUser($member)->inspect('update', $goal)->status());
        $this->assertSame(404, Gate::forUser($admin)->inspect('view', $goal)->status());

        $this->actingAs($member);
        $this->get(route('goals.edit', $goal))->assertNotFound();
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->set('inviteEmail', $admin->email)->call('invite')->assertNotFound();
        Livewire::test('pages::goals.index')
            ->call('setStatus', $goal->id, GoalStatus::Paused->value)->assertNotFound();
        $this->assertSame(GoalStatus::Active, $goal->fresh()->status);
    }

    public function test_recipient_accepts_or_declines_own_pending_invitation_and_stale_responses_fail(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $other = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create();
        $accepted = GoalMembership::factory()->for($goal)->for($recipient)->create();
        $declined = GoalMembership::factory()->for(Goal::factory()->for($owner))->for($recipient)->create();

        $this->actingAs($recipient);
        Livewire::test('pages::goals.index')->call('respondToInvitation', $accepted->id, 'accepted')->assertHasNoErrors();
        Livewire::test('pages::goals.index')->call('respondToInvitation', $declined->id, 'declined')->assertHasNoErrors();

        $this->assertSame(GoalMembershipStatus::Accepted, $accepted->refresh()->status);
        $this->assertNotNull($accepted->responded_at);
        $this->assertSame(GoalMembershipStatus::Declined, $declined->refresh()->status);
        $this->get(route('goals.allocations', $goal))->assertOk();
        Livewire::test('pages::goals.index')->call('respondToInvitation', $accepted->id, 'declined')->assertHasErrors('invitation');

        $this->actingAs($other);
        Livewire::test('pages::goals.index')->call('respondToInvitation', $accepted->id, 'declined')->assertNotFound();
        $this->actingAs($owner);
        Livewire::test('pages::goals.index')->call('respondToInvitation', $accepted->id, 'declined')->assertNotFound();
    }

    public function test_pending_and_declined_memberships_do_not_grant_goal_access(): void
    {
        $owner = User::factory()->create();
        $pendingUser = User::factory()->create();
        $declinedUser = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['name' => 'Shared Future']);
        GoalMembership::factory()->for($goal)->for($pendingUser)->create();
        GoalMembership::factory()->for($goal)->for($declinedUser)->declined()->create();

        foreach ([$pendingUser, $declinedUser] as $user) {
            $this->actingAs($user);
            $this->get(route('goals.allocations', $goal))->assertNotFound();
            $this->assertCount(0, Goal::query()->sharedWith($user)->get());
            $this->assertSame(404, Gate::forUser($user)->inspect('view', $goal)->status());
        }

        $this->actingAs($pendingUser);
        Livewire::test('pages::goals.index')->assertSeeText(['Pending Invitations', 'Shared Future']);
        $this->actingAs($declinedUser);
        Livewire::test('pages::goals.index')->assertDontSeeText('Shared Future');
    }

    public function test_stale_owner_membership_never_duplicates_an_owned_goal_as_shared(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['name' => 'Owned Once']);
        GoalMembership::factory()->for($goal)->for($owner)->accepted()->create();
        $this->actingAs($owner);

        $page = Livewire::test('pages::goals.index');

        $this->assertCount(1, $page->get('ownedGoals'));
        $this->assertCount(1, $page->get('personalGoals'));
        $this->assertCount(0, $page->get('sharedGoals'));
        $page->assertSeeText('Owned Once');
    }

    public function test_owner_goal_classification_uses_only_accepted_member_memberships(): void
    {
        $owner = User::factory()->create();
        $personalGoal = Goal::factory()->for($owner)->create(['name' => 'A Personal Goal']);
        $pendingGoal = Goal::factory()->for($owner)->create(['name' => 'B Pending Goal']);
        $declinedGoal = Goal::factory()->for($owner)->create(['name' => 'C Declined Goal']);
        $sharedGoal = Goal::factory()->for($owner)->create(['name' => 'D Shared Goal']);
        GoalMembership::factory()->for($pendingGoal)->for(User::factory())->create();
        GoalMembership::factory()->for($declinedGoal)->for(User::factory())->declined()->create();
        GoalMembership::factory()->for($sharedGoal)->for(User::factory())->accepted()->create();
        $this->actingAs($owner);

        $page = Livewire::test('pages::goals.index');

        $this->assertSame(
            [$personalGoal->id, $pendingGoal->id, $declinedGoal->id],
            $page->get('personalGoals')->pluck('id')->all(),
        );
        $this->assertSame([$sharedGoal->id], $page->get('sharedGoals')->pluck('id')->all());
        $page->assertSeeText('Shared')->assertDontSeeText('Shared with me');
    }

    public function test_invited_user_goal_classification_requires_their_accepted_membership(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $acceptedGoal = Goal::factory()->for($owner)->create(['name' => 'A Accepted Goal']);
        $pendingGoal = Goal::factory()->for($owner)->create(['name' => 'B Pending Goal']);
        $declinedGoal = Goal::factory()->for($owner)->create(['name' => 'C Declined Goal']);
        GoalMembership::factory()->for($acceptedGoal)->for($member)->accepted()->create();
        GoalMembership::factory()->for($pendingGoal)->for($member)->create();
        GoalMembership::factory()->for($declinedGoal)->for($member)->declined()->create();
        $this->actingAs($member);

        $page = Livewire::test('pages::goals.index');

        $this->assertSame([], $page->get('personalGoals')->pluck('id')->all());
        $this->assertSame([$acceptedGoal->id], $page->get('sharedGoals')->pluck('id')->all());
    }

    public function test_owned_goal_with_multiple_accepted_members_appears_once_only_in_shared(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['name' => 'Unique Shared Goal']);
        GoalMembership::factory()->count(3)->for($goal)->accepted()->create();
        $this->actingAs($owner);

        $page = Livewire::test('pages::goals.index');

        $this->assertSame([], $page->get('personalGoals')->pluck('id')->all());
        $this->assertSame([$goal->id], $page->get('sharedGoals')->pluck('id')->all());
    }

    public function test_owned_goal_remains_shared_until_the_last_accepted_member_leaves(): void
    {
        $owner = User::factory()->create();
        $firstMember = User::factory()->create();
        $secondMember = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create();
        GoalMembership::factory()->for($goal)->for($firstMember)->accepted()->create();
        GoalMembership::factory()->for($goal)->for($secondMember)->accepted()->create();

        app(EndGoalMembership::class)->handle($firstMember, $goal->id, $firstMember->id);
        $this->actingAs($owner);
        $pageWithOneMember = Livewire::test('pages::goals.index');

        $this->assertSame([], $pageWithOneMember->get('personalGoals')->pluck('id')->all());
        $this->assertSame([$goal->id], $pageWithOneMember->get('sharedGoals')->pluck('id')->all());

        app(EndGoalMembership::class)->handle($secondMember, $goal->id, $secondMember->id);
        $pageWithoutMembers = Livewire::test('pages::goals.index');

        $this->assertSame([$goal->id], $pageWithoutMembers->get('personalGoals')->pluck('id')->all());
        $this->assertSame([], $pageWithoutMembers->get('sharedGoals')->pluck('id')->all());
    }

    public function test_unrelated_user_sees_an_owned_shared_goal_in_neither_section(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $unrelatedUser = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create();
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $this->actingAs($unrelatedUser);

        $page = Livewire::test('pages::goals.index');

        $this->assertSame([], $page->get('personalGoals')->pluck('id')->all());
        $this->assertSame([], $page->get('sharedGoals')->pluck('id')->all());
    }

    public function test_shared_section_actions_follow_goal_ownership(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '123.45']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();

        $this->actingAs($owner);
        $ownerPage = Livewire::test('pages::goals.index')
            ->assertSeeText(['Manage', 'Edit', 'Change status'])
            ->assertSee(route('goals.edit', $goal));

        $this->assertSame([$goal->id], $ownerPage->get('sharedGoals')->pluck('id')->all());
        $this->assertSame(1, $ownerPage->get('activeGoals'));
        $this->assertSame('123.45', $ownerPage->get('totalTarget'));
        $this->get(route('goals.edit', $goal))->assertOk();
        $ownerPage->call('setStatus', $goal->id, GoalStatus::Paused->value)->assertHasNoErrors();
        $this->assertSame(GoalStatus::Paused, $goal->fresh()->status);

        $this->actingAs($member);
        $memberPage = Livewire::test('pages::goals.index')
            ->assertSeeText('View goal')
            ->assertDontSeeText(['Manage', 'Edit', 'Change status'])
            ->assertDontSee(route('goals.edit', $goal));

        $this->assertSame([$goal->id], $memberPage->get('sharedGoals')->pluck('id')->all());
    }

    public function test_shared_rows_show_owner_target_date_and_the_standard_funding_progress_bar(): void
    {
        $owner = User::factory()->create(['name' => 'Shared Goal Owner']);
        $member = User::factory()->create(['name' => 'Shared Goal Member', 'currency' => 'BRL']);
        $goal = Goal::factory()->for($owner)->create([
            'name' => 'Shared Visual Goal',
            'target_amount' => '200.00',
            'target_date' => '2027-06-30',
        ]);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($member))->create(['amount' => '50.00']);
        $this->actingAs($member);

        $page = Livewire::test('pages::goals.index');

        $page->assertSeeText(['Shared Visual Goal', 'Shared Goal Owner', 'Owner', 'Target Date', 'Jun 30, 2027', 'R$ 50.00', 'R$ 200.00', '25.0%'])
            ->assertSee('role="progressbar" aria-label="Shared Visual Goal funding progress"', escape: false)
            ->assertSee('aria-valuenow="25.0"', escape: false)
            ->assertSee('bg-orange-500', escape: false);
    }

    public function test_owner_and_member_allocate_only_their_own_accounts_into_combined_goal_capacity(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '40000.00']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $ownerAccount = Account::factory()->for($owner)->create(['current_balance' => '20000.00']);
        $memberAccount = Account::factory()->for($member)->create(['current_balance' => '20000.00']);

        app(SaveGoalAccountAllocation::class)->handle($owner, $goal->id, $ownerAccount->id, '15000.00');
        app(SaveGoalAccountAllocation::class)->handle($member, $goal->id, $memberAccount->id, '10000.00');

        $this->assertSame('25000.00', $goal->fresh()->allocatedAmount());
        $this->assertSame('15000.00', $goal->remainingAmount());
        $this->assertSame('62.5', $goal->progressPercentage());
        $this->assertSame('10000.00', $memberAccount->fresh()->allocatedAmount());

        foreach ([[$owner, $memberAccount], [$member, $ownerAccount]] as [$actor, $foreignAccount]) {
            try {
                app(SaveGoalAccountAllocation::class)->handle($actor, $goal->id, $foreignAccount->id, '1.00');
                $this->fail('A foreign Account allocation should be rejected.');
            } catch (NotFoundHttpException) {
                $this->assertSame(2, GoalAccountAllocation::query()->count());
            }
        }
    }

    public function test_unrelated_pending_and_declined_users_cannot_allocate(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create();
        $users = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        GoalMembership::factory()->for($goal)->for($users[0])->create();
        GoalMembership::factory()->for($goal)->for($users[1])->declined()->create();

        foreach ($users as $user) {
            $account = Account::factory()->for($user)->create();
            try {
                app(SaveGoalAccountAllocation::class)->handle($user, $goal->id, $account->id, '1.00');
                $this->fail('An inaccessible Goal allocation should be rejected.');
            } catch (NotFoundHttpException) {
                $this->assertDatabaseCount('goal_account_allocations', 0);
            }
        }
    }

    public function test_participants_cannot_edit_or_remove_each_others_allocations(): void
    {
        [$owner, $member, $goal, $ownerAllocation, $memberAllocation] = $this->sharedGoalWithAllocations();

        $this->actingAs($member);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('editAllocation', $ownerAllocation->id)->assertNotFound();
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('removeAllocation', $ownerAllocation->id)->assertNotFound();

        $this->actingAs($owner);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('editAllocation', $memberAllocation->id)->assertNotFound();
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('removeAllocation', $memberAllocation->id)->assertNotFound();

        $this->assertModelExists($ownerAllocation);
        $this->assertModelExists($memberAllocation);
    }

    public function test_account_capacity_combines_personal_and_multiple_shared_goal_allocations_exactly(): void
    {
        $user = User::factory()->create();
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $account = Account::factory()->for($user)->create(['current_balance' => '1000.10']);
        $personalGoal = Goal::factory()->for($user)->create(['target_amount' => '1000.00']);
        $firstSharedGoal = Goal::factory()->for($firstOwner)->create(['target_amount' => '1000.00']);
        $secondSharedGoal = Goal::factory()->for($secondOwner)->create(['target_amount' => '1000.00']);
        GoalMembership::factory()->for($firstSharedGoal)->for($user)->accepted()->create();
        GoalMembership::factory()->for($secondSharedGoal)->for($user)->accepted()->create();

        app(SaveGoalAccountAllocation::class)->handle($user, $personalGoal->id, $account->id, '100.01');
        app(SaveGoalAccountAllocation::class)->handle($user, $firstSharedGoal->id, $account->id, '200.02');
        app(SaveGoalAccountAllocation::class)->handle($user, $secondSharedGoal->id, $account->id, '300.03');

        $this->assertSame('600.06', $account->fresh()->allocatedAmount());
        $this->assertSame('400.04', $account->availableAmount());
        $this->assertSame('1000.10', $account->current_balance);
    }

    public function test_shared_goal_capacity_uses_all_participants_and_overfunding_allows_only_reduction(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $ownerAccount = Account::factory()->for($owner)->create(['current_balance' => '200.00']);
        $memberAccount = Account::factory()->for($member)->create(['current_balance' => '200.00']);
        GoalAccountAllocation::factory()->for($goal)->for($ownerAccount)->create(['amount' => '80.00']);

        app(SaveGoalAccountAllocation::class)->handle($member, $goal->id, $memberAccount->id, '20.00');
        try {
            app(SaveGoalAccountAllocation::class)->handle($member, $goal->id, $memberAccount->id, '20.01', GoalAccountAllocation::query()->whereBelongsTo($memberAccount)->sole()->id);
            $this->fail('Combined Goal capacity should reject the increase.');
        } catch (ValidationException) {
            $this->assertSame('100.00', $goal->fresh()->allocatedAmount());
        }

        $goal->update(['target_amount' => '50.00']);
        $memberAllocation = GoalAccountAllocation::query()->whereBelongsTo($memberAccount)->sole();
        app(SaveGoalAccountAllocation::class)->handle($member, $goal->id, $memberAccount->id, '10.00', $memberAllocation->id);
        $this->assertSame('90.00', $goal->fresh()->allocatedAmount());
    }

    public function test_shared_goal_detail_exposes_member_totals_but_never_other_account_data_or_state(): void
    {
        $owner = User::factory()->create(['name' => 'Owner Person']);
        $member = User::factory()->create(['name' => 'Member Person', 'currency' => 'BRL']);
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '500.00']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $ownerAccount = Account::factory()->for($owner)->create(['name' => 'OWNER SECRET ACCOUNT', 'current_balance' => '9876543.21']);
        $memberAccount = Account::factory()->for($member)->create(['name' => 'Member Savings', 'current_balance' => '300.00']);
        GoalAccountAllocation::factory()->for($goal)->for($ownerAccount)->create(['amount' => '123.45']);
        GoalAccountAllocation::factory()->for($goal)->for($memberAccount)->create(['amount' => '50.00']);
        $this->actingAs($member);

        $page = Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSeeText(['Owner Person', 'Member Savings'])
            ->assertDontSeeText(['OWNER SECRET ACCOUNT', '9876543.21']);
        $allocations = $page->get('allocations');
        $contributions = $page->get('contributions');
        $goalState = $page->get('goal')->toArray();

        $this->assertCount(1, $allocations);
        $this->assertSame('123.45', $contributions[0]['amount']);
        $this->assertSame('50.00', $contributions[1]['amount']);
        $this->assertSame($memberAccount->id, $allocations->sole()->account_id);
        $this->assertArrayNotHasKey('account_id', $goalState['goal_account_allocations'][0]);
        $this->assertArrayNotHasKey('email', $goalState['owner']);
    }

    public function test_member_leaving_removes_only_their_allocations_and_access(): void
    {
        [$owner, $member, $goal, $ownerAllocation, $memberAllocation] = $this->sharedGoalWithAllocations();
        $this->actingAs($member);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])->call('leaveGoal')->assertRedirect(route('goals.index'));

        $this->assertModelMissing($memberAllocation);
        $this->assertModelExists($ownerAllocation);
        $this->assertDatabaseMissing('goal_memberships', ['goal_id' => $goal->id, 'user_id' => $member->id]);
        $this->assertSame('40.00', $goal->fresh()->allocatedAmount());
        $this->get(route('goals.allocations', $goal))->assertNotFound();
        $this->assertModelExists($owner);
    }

    public function test_owner_removing_member_removes_only_that_members_allocations(): void
    {
        [$owner, $member, $goal, $ownerAllocation, $memberAllocation] = $this->sharedGoalWithAllocations();
        $this->actingAs($owner);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])->call('removeMember', $member->id)->assertHasNoErrors();

        $this->assertModelMissing($memberAllocation);
        $this->assertModelExists($ownerAllocation);
        $this->assertDatabaseMissing('goal_memberships', ['goal_id' => $goal->id, 'user_id' => $member->id]);
        $this->assertSame(404, Gate::forUser($member)->inspect('view', $goal)->status());
    }

    public function test_member_cannot_remove_another_member_and_owner_cannot_leave(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create();
        GoalMembership::factory()->for($goal)->for($first)->accepted()->create();
        GoalMembership::factory()->for($goal)->for($second)->accepted()->create();

        $this->actingAs($first);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])->call('removeMember', $second->id)->assertNotFound();
        $this->actingAs($owner);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])->call('leaveGoal')->assertForbidden();

        $this->assertSame(2, GoalMembership::query()->count());
    }

    public function test_dashboard_reports_and_assets_integrate_shared_goals_without_account_privacy_or_asset_transfer(): void
    {
        $owner = User::factory()->create(['name' => 'Owner Name']);
        $member = User::factory()->create(['name' => 'Member Name']);
        $pending = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['name' => 'Together Goal', 'target_amount' => '400.00', 'status' => GoalStatus::Active]);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        GoalMembership::factory()->for($goal)->for($pending)->create();
        $ownerAccount = Account::factory()->for($owner)->create(['name' => 'OWNER PRIVATE', 'current_balance' => '900.00']);
        $memberAccount = Account::factory()->for($member)->create(['name' => 'My Shared Savings', 'current_balance' => '200.00']);
        GoalAccountAllocation::factory()->for($goal)->for($ownerAccount)->create(['amount' => '150.00']);
        GoalAccountAllocation::factory()->for($goal)->for($memberAccount)->create(['amount' => '50.00']);
        $this->actingAs($member);

        Livewire::test('pages::dashboard')->assertSeeText(['Together Goal', 'Shared', '50.0%'])->assertDontSeeText(['OWNER PRIVATE', '900.00']);
        $goalsReport = app(GoalsReport::class)->handle($member, $goal->id);
        $assetsReport = app(AssetsReport::class)->handle($member, CarbonImmutable::now()->subMonth(), CarbonImmutable::now());

        $this->assertSame('200.00', $goalsReport['goals'][0]['allocated']);
        $this->assertSame(['Your Accounts', 'Other Members'], array_column($goalsReport['fundingSources'], 'group'));
        $this->assertSame(['My Shared Savings', 'Owner Name'], array_column($goalsReport['fundingSources'], 'name'));
        $this->assertSame('200.00', $assetsReport['totalAssets']);
        $this->assertSame('50.00', $assetsReport['allocatedAssets']);
        $this->assertSame('150.00', $assetsReport['freeAssets']);
        $this->assertSame('200.00', $memberAccount->fresh()->current_balance);

        $this->actingAs($pending);
        Livewire::test('pages::dashboard')->assertDontSeeText('Together Goal');
        $this->assertSame([], app(GoalsReport::class)->handle($pending)['goals']);
    }

    public function test_user_and_goal_deletion_clean_memberships_without_orphans(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create();
        $membership = GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $memberAllocation = GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($member))->create();

        $this->actingAs($member);
        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')->call('deleteUser')->assertRedirect('/');
        $this->assertModelMissing($membership);
        $this->assertModelMissing($memberAllocation);
        $this->assertModelExists($goal);

        $secondMember = User::factory()->create();
        $secondMembership = GoalMembership::factory()->for($goal)->for($secondMember)->accepted()->create();
        $this->actingAs($owner);
        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')->call('deleteUser')->assertRedirect('/');
        $this->assertModelMissing($goal);
        $this->assertModelMissing($secondMembership);

        $otherGoal = Goal::factory()->create();
        $goalMembership = GoalMembership::factory()->for($otherGoal)->for(User::factory())->create();
        $otherGoal->delete();
        $this->assertModelMissing($goalMembership);
    }

    /** @return array{User, User, Goal, GoalAccountAllocation, GoalAccountAllocation} */
    private function sharedGoalWithAllocations(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $ownerAllocation = GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($owner))->create(['amount' => '40.00']);
        $memberAllocation = GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($member))->create(['amount' => '30.00']);

        return [$owner, $member, $goal, $ownerAllocation, $memberAllocation];
    }
}
