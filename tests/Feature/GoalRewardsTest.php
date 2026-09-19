<?php

namespace Tests\Feature;

use App\Actions\DeleteGoalReward;
use App\Actions\EvaluateGoalMilestones;
use App\Actions\SaveGoalAccountAllocation;
use App\Actions\SaveGoalReward;
use App\GoalMilestone;
use App\GoalStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\GoalMembership;
use App\Models\GoalMilestoneAchievement;
use App\Models\GoalMilestoneNotification;
use App\Models\GoalReward;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoalRewardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocation_increase_unlocks_once_and_notifies_the_owner_once(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        $account = Account::factory()->for($owner)->create(['current_balance' => '100.00']);
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '24.00']);

        app(SaveGoalAccountAllocation::class)->handle($owner, $goal->id, $account->id, '26.00', $allocation->id);
        app(EvaluateGoalMilestones::class)->handle($goal);

        $achievement = GoalMilestoneAchievement::query()->sole();
        $this->assertSame(GoalMilestone::TwentyFive, $achievement->milestone_percentage);
        $this->assertSame($owner->id, GoalMilestoneNotification::query()->sole()->user_id);
    }

    public function test_large_jump_persists_every_crossed_milestone_but_notifies_only_the_highest(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        $account = Account::factory()->for($owner)->create(['current_balance' => '100.00']);
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '10.00']);

        app(SaveGoalAccountAllocation::class)->handle($owner, $goal->id, $account->id, '80.00', $allocation->id);

        $this->assertSame([25, 50, 75], $this->achievementPercentages());
        $notification = GoalMilestoneNotification::query()->with('achievement')->sole();
        $this->assertSame(GoalMilestone::SeventyFive, $notification->achievement->milestone_percentage);
    }

    public function test_overfunded_goal_unlocks_one_hundred_without_changing_goal_status(): void
    {
        $goal = Goal::factory()->create(['target_amount' => '100.00', 'status' => GoalStatus::Active]);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '105.00']);

        app(EvaluateGoalMilestones::class)->handle($goal);

        $this->assertSame([25, 50, 75, 100], $this->achievementPercentages());
        $this->assertSame('105.0', $goal->fresh()->progressPercentage());
        $this->assertSame(GoalStatus::Active, $goal->status);
    }

    public function test_achievements_survive_decreases_and_are_not_replayed_when_progress_recovers(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        $account = Account::factory()->for($owner)->create(['current_balance' => '100.00']);
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '10.00']);
        $save = app(SaveGoalAccountAllocation::class);

        $save->handle($owner, $goal->id, $account->id, '75.00', $allocation->id);
        $save->handle($owner, $goal->id, $account->id, '40.00', $allocation->id);
        $save->handle($owner, $goal->id, $account->id, '75.00', $allocation->id);

        $this->assertSame([25, 50, 75], $this->achievementPercentages());
        $this->assertDatabaseCount('goal_milestone_notifications', 1);
    }

    public function test_currently_satisfied_achievements_render_green_without_historical_explanation(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '60.00']);
        GoalMilestoneAchievement::factory()->for($goal)->create(['milestone_percentage' => GoalMilestone::TwentyFive]);
        GoalMilestoneAchievement::factory()->for($goal)->create(['milestone_percentage' => GoalMilestone::Fifty]);
        $this->actingAs($owner);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSee('data-milestone-percentage="25" data-milestone-state="achieved_current"', escape: false)
            ->assertSee('data-milestone-percentage="50" data-milestone-state="achieved_current"', escape: false)
            ->assertDontSeeText('Current progress:');
    }

    public function test_achieved_milestones_above_current_progress_render_as_historical(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '40.00']);
        foreach ([GoalMilestone::TwentyFive, GoalMilestone::Fifty, GoalMilestone::SeventyFive] as $milestone) {
            GoalMilestoneAchievement::factory()->for($goal)->create(['milestone_percentage' => $milestone]);
        }
        $this->actingAs($owner);

        $component = Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSee('data-milestone-percentage="25" data-milestone-state="achieved_current"', escape: false)
            ->assertSee('data-milestone-percentage="50" data-milestone-state="achieved_historical"', escape: false)
            ->assertSee('data-milestone-percentage="75" data-milestone-state="achieved_historical"', escape: false)
            ->assertSee('data-milestone-percentage="100" data-milestone-state="upcoming"', escape: false)
            ->assertSeeText(['Previously reached', 'Current progress: 40.0%', 'Upcoming']);

        $this->assertSame(2, substr_count($component->html(), 'Current progress: 40.0%'));
    }

    /** @param list<int> $achievementPercentages */
    #[DataProvider('currentlySupportedListingMilestoneProvider')]
    public function test_listing_shows_highest_currently_supported_persisted_achievement(
        string $allocatedAmount,
        array $achievementPercentages,
        GoalStatus $status,
        string $expectedBadge,
        string $excludedBadge,
    ): void {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00', 'status' => $status]);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => $allocatedAmount]);
        foreach ($achievementPercentages as $percentage) {
            GoalMilestoneAchievement::factory()->for($goal)->create([
                'milestone_percentage' => GoalMilestone::from($percentage),
            ]);
        }
        $this->actingAs($owner);

        Livewire::test('pages::goals.index')
            ->assertSeeText($expectedBadge)
            ->assertDontSeeText($excludedBadge);
    }

    /** @return array<string, array{string, list<int>, GoalStatus, string, string}> */
    public static function currentlySupportedListingMilestoneProvider(): array
    {
        return [
            '40 percent retains only 25' => ['40.00', [25, 50, 75], GoalStatus::Active, '✓ 25% achieved', '✓ 75% achieved'],
            '62 percent retains 50' => ['62.00', [25, 50, 75], GoalStatus::Active, '✓ 50% achieved', '✓ 75% achieved'],
            '80 percent retains 75' => ['80.00', [25, 50, 75], GoalStatus::Active, '✓ 75% achieved', '✓ 100% achieved'],
            '100 percent retains 100' => ['100.00', [25, 50, 75, 100], GoalStatus::Active, '✓ 100% achieved', '✓ 75% achieved'],
            'missing 50 record does not infer it' => ['60.00', [25], GoalStatus::Active, '✓ 25% achieved', '✓ 50% achieved'],
            'completed status does not infer 100' => ['60.00', [25, 50], GoalStatus::Completed, '✓ 50% achieved', '✓ 100% achieved'],
        ];
    }

    public function test_listing_omits_badge_when_all_achievements_are_historical(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '20.00']);
        foreach ([GoalMilestone::TwentyFive, GoalMilestone::Fifty, GoalMilestone::SeventyFive] as $milestone) {
            GoalMilestoneAchievement::factory()->for($goal)->create(['milestone_percentage' => $milestone]);
        }
        $this->actingAs($owner);

        Livewire::test('pages::goals.index')
            ->assertDontSeeText(['✓ 25% achieved', '✓ 50% achieved', '✓ 75% achieved', '✓ 100% achieved']);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSee('data-milestone-percentage="25" data-milestone-state="achieved_historical"', escape: false)
            ->assertSee('data-milestone-percentage="50" data-milestone-state="achieved_historical"', escape: false)
            ->assertSee('data-milestone-percentage="75" data-milestone-state="achieved_historical"', escape: false);
    }

    public function test_exact_milestone_boundary_remains_currently_achieved(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '50.00']);
        GoalMilestoneAchievement::factory()->for($goal)->create(['milestone_percentage' => GoalMilestone::Fifty]);
        $this->actingAs($owner);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSee('data-milestone-percentage="50" data-milestone-state="achieved_current"', escape: false)
            ->assertDontSeeText('Current progress:');
    }

    public function test_progress_recovery_restores_green_without_changing_history_reward_or_notifications(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        $account = Account::factory()->for($owner)->create(['current_balance' => '100.00']);
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '75.00']);
        $reward = GoalReward::factory()->for($goal)->create([
            'milestone_percentage' => GoalMilestone::SeventyFive,
            'title' => 'Unlocked reward',
        ]);
        app(EvaluateGoalMilestones::class)->handle($goal);
        $achievement = GoalMilestoneAchievement::query()->where('milestone_percentage', 75)->sole();
        $originalAchievedAt = $achievement->achieved_at->toDateTimeString();
        $allocation->update(['amount' => '40.00']);
        $this->actingAs($owner);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSee('data-milestone-percentage="75" data-milestone-state="achieved_historical"', escape: false)
            ->assertSeeText(['Current progress: 40.0%', 'Reward unlocked', 'Unlocked reward'])
            ->assertDontSeeText('Edit reward')
            ->assertDontSee('wire:click="removeReward(', escape: false);
        Livewire::test('pages::goals.index')
            ->assertSeeText('✓ 25% achieved')
            ->assertDontSeeText('✓ 75% achieved');

        app(SaveGoalAccountAllocation::class)->handle($owner, $goal->id, $account->id, '80.00', $allocation->id);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSee('data-milestone-percentage="75" data-milestone-state="achieved_current"', escape: false)
            ->assertDontSeeText('Current progress:');
        Livewire::test('pages::goals.index')->assertSeeText('✓ 75% achieved');
        $this->assertDatabaseCount('goal_milestone_achievements', 3);
        $this->assertDatabaseCount('goal_milestone_notifications', 1);
        $this->assertSame($originalAchievedAt, $achievement->fresh()->achieved_at->toDateTimeString());
        $this->assertSame('Unlocked reward', $reward->fresh()->title);
    }

    public function test_shared_goal_participants_see_historical_states_from_combined_progress(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $privateAccount = Account::factory()->for($owner)->create(['name' => 'OWNER PRIVATE']);
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($privateAccount)->create(['amount' => '75.00']);
        app(EvaluateGoalMilestones::class)->handle($goal);
        $allocation->update(['amount' => '40.00']);

        $this->actingAs($owner);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSee('data-milestone-percentage="25" data-milestone-state="achieved_current"', escape: false)
            ->assertSee('data-milestone-percentage="50" data-milestone-state="achieved_historical"', escape: false)
            ->assertSee('data-milestone-percentage="75" data-milestone-state="achieved_historical"', escape: false)
            ->assertSeeText('Current progress: 40.0%');

        $this->actingAs($member);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSee('data-milestone-percentage="25" data-milestone-state="achieved_current"', escape: false)
            ->assertSee('data-milestone-percentage="50" data-milestone-state="achieved_historical"', escape: false)
            ->assertSee('data-milestone-percentage="75" data-milestone-state="achieved_historical"', escape: false)
            ->assertSeeText('Current progress: 40.0%')
            ->assertDontSeeText('OWNER PRIVATE');

        foreach ([$owner, $member] as $participant) {
            $this->actingAs($participant);
            Livewire::test('pages::goals.index')
                ->assertSeeText('✓ 25% achieved')
                ->assertDontSeeText('✓ 75% achieved');
        }
    }

    public function test_locked_target_edits_unlock_milestones_but_target_increases_do_not_revoke_them(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['name' => 'Target Goal', 'target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($owner))->create(['amount' => '49.00']);
        app(EvaluateGoalMilestones::class)->handle($goal);
        $this->actingAs($owner);

        Livewire::test('pages::goals.form', ['goalId' => $goal->id])
            ->set(['name' => 'Target Goal', 'target_amount' => '80.00', 'target_date' => null])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([25, 50], $this->achievementPercentages());

        Livewire::test('pages::goals.form', ['goalId' => $goal->id])
            ->set(['name' => 'Target Goal', 'target_amount' => '200.00', 'target_date' => null])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([25, 50], $this->achievementPercentages());
    }

    public function test_manual_completed_status_does_not_unlock_funding_milestones(): void
    {
        $goal = Goal::factory()->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '60.00']);
        $this->actingAs($goal->user);

        Livewire::test('pages::goals.index')
            ->call('setStatus', $goal->id, GoalStatus::Completed->value)
            ->assertHasNoErrors();

        $this->assertSame(GoalStatus::Completed, $goal->fresh()->status);
        $this->assertDatabaseCount('goal_milestone_achievements', 0);
    }

    public function test_shared_milestone_uses_combined_funding_and_notifies_only_current_participants(): void
    {
        $owner = User::factory()->create();
        $accepted = User::factory()->create();
        $pending = User::factory()->create();
        $declined = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalMembership::factory()->for($goal)->for($accepted)->accepted()->create();
        GoalMembership::factory()->for($goal)->for($pending)->create();
        GoalMembership::factory()->for($goal)->for($declined)->declined()->create();
        GoalAccountAllocation::factory()->for($goal)->for(Account::factory()->for($owner))->create(['amount' => '49.00']);
        $memberAccount = Account::factory()->for($accepted)->create(['current_balance' => '50.00']);

        app(SaveGoalAccountAllocation::class)->handle($accepted, $goal->id, $memberAccount->id, '3.00');

        $this->assertSame(GoalMilestone::Fifty, GoalMilestoneAchievement::query()->where('milestone_percentage', 50)->sole()->milestone_percentage);
        $this->assertSame(
            [$owner->id, $accepted->id],
            GoalMilestoneNotification::query()->orderBy('user_id')->pluck('user_id')->all(),
        );
    }

    public function test_late_member_sees_history_without_retroactive_notifications(): void
    {
        $owner = User::factory()->create();
        $lateMember = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '75.00']);
        app(EvaluateGoalMilestones::class)->handle($goal);
        GoalMembership::factory()->for($goal)->for($lateMember)->accepted()->create();
        $this->actingAs($lateMember);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSeeText(['✓ 25%', '✓ 50%', '✓ 75%', '○ 100%']);
        Livewire::test('pages::goals.index')->assertSeeText('✓ 75% achieved');

        $this->assertDatabaseMissing('goal_milestone_notifications', ['user_id' => $lateMember->id]);
    }

    public function test_owner_manages_unachieved_reward_member_is_read_only_and_achievement_freezes_it(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        $this->actingAs($owner);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('startAddReward', 50)
            ->set('rewardTitle', 'Italian dinner')
            ->set('rewardDescription', 'Celebrate together')
            ->call('saveReward')
            ->assertHasNoErrors()
            ->assertSeeText(['Italian dinner', 'Celebrate together', 'Edit reward', 'Remove']);

        $reward = GoalReward::query()->sole();
        $this->actingAs($member);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSeeText(['Italian dinner', 'Celebrate together'])
            ->assertDontSeeText(['Edit reward', 'Remove'])
            ->call('editReward', $reward->id)
            ->assertNotFound();

        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '50.00']);
        app(EvaluateGoalMilestones::class)->handle($goal);

        try {
            app(SaveGoalReward::class)->handle($owner, $goal->id, GoalMilestone::Fifty, 'Changed reward', null, $reward->id);
            $this->fail('An achieved Reward should be immutable.');
        } catch (ValidationException) {
            $this->assertSame('Italian dinner', $reward->fresh()->title);
        }

        try {
            app(DeleteGoalReward::class)->handle($owner, $goal->id, $reward->id);
            $this->fail('An achieved Reward should not be removable.');
        } catch (ValidationException) {
            $this->assertModelExists($reward);
        }
    }

    public function test_reward_validation_and_fixed_milestones_are_enforced(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create();
        $this->actingAs($owner);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('startAddReward', 25)
            ->set('rewardTitle', str_repeat('a', 101))
            ->set('rewardDescription', str_repeat('b', 501))
            ->call('saveReward')
            ->assertHasErrors(['rewardTitle', 'rewardDescription']);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('startAddReward', 30)
            ->assertNotFound();
        $this->assertDatabaseCount('goal_rewards', 0);
    }

    public function test_unseen_notification_is_acknowledged_once_per_participant(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['name' => 'Shared Trip', 'target_amount' => '100.00']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '25.00']);
        app(EvaluateGoalMilestones::class)->handle($goal);
        $ownerNotification = GoalMilestoneNotification::query()->where('user_id', $owner->id)->sole();
        $memberNotification = GoalMilestoneNotification::query()->where('user_id', $member->id)->sole();
        $this->actingAs($owner);

        Livewire::test('goal-achievement-celebration')
            ->call('acknowledge', $memberNotification->id)
            ->assertNotFound();
        $this->assertNull($memberNotification->fresh()->seen_at);

        Livewire::test('goal-achievement-celebration')
            ->assertSeeText(['25% reached!', 'Shared Trip'])
            ->call('acknowledge', $ownerNotification->id)
            ->assertDontSeeText('25% reached!');

        $this->assertNotNull($ownerNotification->fresh()->seen_at);
        $this->assertNull($memberNotification->fresh()->seen_at);
    }

    public function test_multiple_unseen_notifications_are_presented_oldest_first_one_at_a_time(): void
    {
        $owner = User::factory()->create();
        $quarterGoal = Goal::factory()->for($owner)->create(['name' => 'Quarter Goal', 'target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($quarterGoal)->create(['amount' => '25.00']);
        app(EvaluateGoalMilestones::class)->handle($quarterGoal);
        $quarterNotification = GoalMilestoneNotification::query()->whereHas(
            'achievement',
            fn ($query) => $query->where('goal_id', $quarterGoal->id),
        )->sole();

        $halfGoal = Goal::factory()->for($owner)->create(['name' => 'Half Goal', 'target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($halfGoal)->create(['amount' => '50.00']);
        app(EvaluateGoalMilestones::class)->handle($halfGoal);
        $this->actingAs($owner);

        Livewire::test('goal-achievement-celebration')
            ->assertSeeText(['25% reached!', 'Quarter Goal'])
            ->assertDontSeeText(['Halfway there!', 'Half Goal'])
            ->call('acknowledge', $quarterNotification->id)
            ->assertSeeText(['Halfway there!', 'Half Goal'])
            ->assertDontSeeText('Quarter Goal');
    }

    public function test_one_hundred_celebration_renders_reward_and_combined_funding(): void
    {
        $owner = User::factory()->create(['currency' => 'BRL']);
        $goal = Goal::factory()->for($owner)->create(['name' => 'Italy Trip', 'target_amount' => '40000.00']);
        GoalReward::factory()->for($goal)->create([
            'milestone_percentage' => GoalMilestone::OneHundred,
            'title' => 'Trip unlocked',
        ]);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '40000.00']);
        app(EvaluateGoalMilestones::class)->handle($goal);
        $this->actingAs($owner);

        Livewire::test('goal-achievement-celebration')
            ->assertSeeText(['Goal funded!', 'Italy Trip', 'R$ 40000.00', 'Reward unlocked', 'Trip unlocked', 'Continue'])
            ->assertSee('role="dialog"', escape: false)
            ->assertSee('aria-modal="true"', escape: false);
    }

    public function test_membership_end_removes_unseen_notifications_without_removing_achievements(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $goal = Goal::factory()->for($owner)->create(['target_amount' => '100.00']);
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '25.00']);
        app(EvaluateGoalMilestones::class)->handle($goal);
        $this->actingAs($member);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('leaveGoal')
            ->assertRedirect(route('goals.index'));

        $this->assertDatabaseMissing('goal_milestone_notifications', ['user_id' => $member->id]);
        $this->assertDatabaseCount('goal_milestone_achievements', 1);
        $this->assertDatabaseHas('goal_milestone_notifications', ['user_id' => $owner->id]);
    }

    public function test_goal_and_user_deletion_cascade_reward_history_and_notification_rows(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create();
        $achievement = GoalMilestoneAchievement::factory()->for($goal)->create();
        $reward = GoalReward::factory()->for($goal)->create();
        $notification = GoalMilestoneNotification::factory()->for($achievement, 'achievement')->for($user)->create();

        $goal->delete();

        $this->assertModelMissing($achievement);
        $this->assertModelMissing($reward);
        $this->assertModelMissing($notification);

        $secondGoal = Goal::factory()->create();
        $secondAchievement = GoalMilestoneAchievement::factory()->for($secondGoal)->create();
        $secondUser = User::factory()->create();
        $secondNotification = GoalMilestoneNotification::factory()->for($secondAchievement, 'achievement')->for($secondUser)->create();
        $secondUser->delete();

        $this->assertModelMissing($secondNotification);
        $this->assertModelExists($secondAchievement);
    }

    public function test_shared_reward_view_preserves_account_privacy_and_admin_has_no_bypass(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $goal = Goal::factory()->for($owner)->create();
        GoalMembership::factory()->for($goal)->for($member)->accepted()->create();
        GoalReward::factory()->for($goal)->create(['title' => 'Visible reward']);
        $privateAccount = Account::factory()->for($owner)->create(['name' => 'OWNER PRIVATE', 'current_balance' => '9999.00']);
        GoalAccountAllocation::factory()->for($goal)->for($privateAccount)->create(['amount' => '50.00']);

        $this->actingAs($member);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSeeText('Visible reward')
            ->assertDontSeeText(['OWNER PRIVATE', '9999.00']);

        $this->actingAs($admin);
        $this->get(route('goals.allocations', $goal))->assertNotFound();
    }

    public function test_backfill_imports_current_achievements_without_notifications(): void
    {
        $this->travelTo('2026-09-19 12:00:00');
        $goal = Goal::factory()->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($goal)->create(['amount' => '75.00']);
        $migration = require database_path('migrations/2026_09_19_040436_backfill_goal_milestone_achievements.php');

        $migration->up();

        $this->assertSame([25, 50, 75], $this->achievementPercentages());
        $this->assertDatabaseCount('goal_milestone_notifications', 0);
        $this->assertSame(
            ['2026-09-19 12:00:00'],
            GoalMilestoneAchievement::query()->pluck('achieved_at')->map->toDateTimeString()->unique()->values()->all(),
        );
    }

    /** @return list<int> */
    private function achievementPercentages(): array
    {
        return array_values(GoalMilestoneAchievement::query()
            ->orderBy('milestone_percentage')
            ->get()
            ->map(fn (GoalMilestoneAchievement $achievement): int => $achievement->milestone_percentage->value)
            ->all());
    }
}
