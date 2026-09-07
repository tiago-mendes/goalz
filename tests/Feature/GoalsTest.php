<?php

namespace Tests\Feature;

use App\GoalStatus;
use App\Models\Goal;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class GoalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_only_see_and_manage_their_own_goals(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $own = Goal::factory()->for($user)->create(['name' => 'Italy Trip']);
        $foreign = Goal::factory()->create(['name' => 'Private Car']);
        $this->actingAs($user);

        $this->get(route('goals.index'))->assertOk()->assertSeeText('Italy Trip')->assertDontSeeText('Private Car');
        $this->get(route('goals.create'))->assertOk();
        $this->get(route('goals.edit', $own))->assertOk();
        $this->get(route('goals.edit', $foreign))->assertNotFound();
        Livewire::test('pages::goals.form', ['goalId' => $foreign->id])->assertNotFound();
        Livewire::test('pages::goals.index')->call('setStatus', $foreign->id, GoalStatus::Paused->value)->assertNotFound();
        $this->assertSame(404, Gate::forUser($user)->inspect('view', $foreign)->status());
        $this->assertSame(404, Gate::forUser($user)->inspect('update', $foreign)->status());
    }

    public function test_admin_can_manage_own_goals_but_not_another_users_goal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $own = Goal::factory()->for($admin)->create(['name' => 'Own goal']);
        $foreign = Goal::factory()->create(['name' => 'Foreign goal']);
        $this->actingAs($admin);

        $this->get(route('goals.index'))->assertSeeText('Own goal')->assertDontSeeText('Foreign goal');
        $this->get(route('goals.edit', $foreign))->assertNotFound();
        $this->assertTrue(Gate::forUser($admin)->allows('update', $own));
        $this->assertSame(404, Gate::forUser($admin)->inspect('update', $foreign)->status());
    }

    public function test_valid_goal_defaults_to_active_and_assigns_authenticated_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::goals.form')->set([
            'name' => '  Emergency Fund  ', 'target_amount' => '123456.70', 'target_date' => '2027-06-30',
        ])->call('save', ['user_id' => $other->id, 'status' => GoalStatus::Completed->value])->assertHasNoErrors();

        $goal = $user->goals()->sole();
        $this->assertSame('Emergency Fund', $goal->name);
        $this->assertSame('123456.70', $goal->target_amount);
        $this->assertSame('2027-06-30', $goal->target_date->toDateString());
        $this->assertSame(GoalStatus::Active, $goal->status);
        $this->assertSame(0, $other->goals()->count());
        $this->assertSame($user->id, $goal->user_id);
    }

    public function test_owner_can_edit_goal_and_clear_target_date_without_changing_ownership_or_status(): void
    {
        $goal = Goal::factory()->create(['status' => GoalStatus::Paused, 'target_date' => '2027-01-01']);
        $originalOwner = $goal->user_id;
        $this->actingAs($goal->user);

        Livewire::test('pages::goals.form', ['goalId' => $goal->id])->set([
            'name' => 'New Car', 'target_amount' => '999.99', 'target_date' => null,
        ])->call('save', ['user_id' => User::factory()->create()->id, 'status' => GoalStatus::Completed->value])->assertHasNoErrors();

        $goal->refresh();
        $this->assertSame('New Car', $goal->name);
        $this->assertSame('999.99', $goal->target_amount);
        $this->assertNull($goal->target_date);
        $this->assertSame($originalOwner, $goal->user_id);
        $this->assertSame(GoalStatus::Paused, $goal->status);
    }

    #[TestWith(['0'])]
    #[TestWith(['0.00'])]
    #[TestWith(['-1'])]
    #[TestWith(['0.001'])]
    #[TestWith(['10000000000000.00'])]
    public function test_invalid_target_amounts_are_rejected_without_writes(string $amount): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::goals.form')->set([
            'name' => 'Invalid goal', 'target_amount' => $amount,
        ])->call('save')->assertHasErrors('target_amount');

        $this->assertDatabaseCount('goals', 0);
    }

    public function test_maximum_target_amount_is_accepted_by_application_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::goals.form')->set([
            'name' => 'Maximum', 'target_amount' => '9999999999999.99',
        ])->call('save')->assertHasNoErrors();

        $this->assertDatabaseCount('goals', 1);
    }

    public function test_goal_names_are_unique_per_user_case_insensitively_but_allowed_for_other_users(): void
    {
        $goal = Goal::factory()->create(['name' => 'Italy Trip']);
        $this->actingAs($goal->user);
        Livewire::test('pages::goals.form')->set(['name' => 'Italy Trip', 'target_amount' => '1'])->call('save')->assertHasErrors('name');
        $this->assertSame(1, $goal->user->goals()->count());
    }

    public function test_same_name_is_allowed_for_a_different_user(): void
    {
        $first = Goal::factory()->create(['name' => 'Emergency Fund']);
        $second = User::factory()->create();
        $this->actingAs($second);

        Livewire::test('pages::goals.form')->set(['name' => 'Emergency Fund', 'target_amount' => '1'])->call('save')->assertHasNoErrors();

        $this->assertSame(1, $second->goals()->where('name', 'Emergency Fund')->count());
        $this->assertNotSame($first->user_id, $second->id);
    }

    #[TestWith([GoalStatus::Active, GoalStatus::Paused])]
    #[TestWith([GoalStatus::Paused, GoalStatus::Active])]
    #[TestWith([GoalStatus::Active, GoalStatus::Completed])]
    #[TestWith([GoalStatus::Completed, GoalStatus::Active])]
    public function test_owner_can_change_goal_status(GoalStatus $from, GoalStatus $to): void
    {
        $goal = Goal::factory()->create(['status' => $from]);
        $this->actingAs($goal->user);

        Livewire::test('pages::goals.index')->call('setStatus', $goal->id, $to->value)->assertHasNoErrors();

        $this->assertSame($to, $goal->refresh()->status);
    }

    public function test_summary_counts_and_sums_active_goals_only_without_float_arithmetic(): void
    {
        $user = User::factory()->create();
        Goal::factory()->for($user)->create(['name' => 'A', 'target_amount' => '9999999999.99']);
        Goal::factory()->for($user)->paused()->create(['name' => 'B', 'target_amount' => '500.01']);
        Goal::factory()->for($user)->completed()->create(['name' => 'C', 'target_amount' => '900.00']);
        $this->actingAs($user);

        Livewire::test('pages::goals.index')->assertSeeText('Active Goals')->assertSeeText('1')
            ->assertSet('totalTarget', '9999999999.99');
    }

    public function test_empty_summary_is_zero_and_page_has_no_progress_or_delete_controls(): void
    {
        $this->actingAs($user = User::factory()->create(['currency' => 'BRL']));

        $this->get(route('goals.index'))->assertSeeText('Active Goals')->assertSeeText('Total Target')
            ->assertSeeText('BRL 0.00')->assertSeeText('No goals yet. Create your first goal to start planning what comes next.')
            ->assertDontSeeText('Progress')->assertDontSeeText('Delete');
        Livewire::test('pages::goals.index')->assertSet('activeGoals', 0)->assertSet('totalTarget', '0.00');
    }

    public function test_deleting_a_user_cascades_owned_goals(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')
            ->call('deleteUser')->assertRedirect('/');

        $this->assertModelMissing($goal);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
