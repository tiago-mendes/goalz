<?php

namespace Tests\Feature;

use App\Actions\SaveGoalAccountAllocation;
use App\GoalStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class GoalAccountAllocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_allocates_an_exact_amount_and_percentage_is_only_input_convenience(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $goal = Goal::factory()->for($user)->create(['name' => 'Italy Trip', 'target_amount' => '20000.00']);
        $account = Account::factory()->for($user)->create(['name' => 'Savings', 'current_balance' => '30000.00']);
        $this->actingAs($user);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->set('accountId', $account->id)
            ->set('percentage', '20.0')
            ->assertSet('amount', '6000.00')
            ->call('save')
            ->assertHasNoErrors();

        $allocation = GoalAccountAllocation::query()->sole();
        $this->assertSame('6000.00', $allocation->amount);
        $this->assertSame('30000.00', $account->refresh()->current_balance);
        $this->assertFalse(Schema::hasColumn('goal_account_allocations', 'allocation_percentage'));
        $this->assertSame(['id', 'goal_id', 'account_id', 'amount', 'created_at', 'updated_at'], Schema::getColumnListing('goal_account_allocations'));
    }

    #[TestWith(['0'])]
    #[TestWith(['0.00'])]
    #[TestWith(['-0.01'])]
    #[TestWith(['1.001'])]
    #[TestWith(['10000000000000.00'])]
    public function test_invalid_amounts_are_rejected_without_writes(string $amount): void
    {
        [$user, $goal, $account] = $this->financialContext();
        $this->actingAs($user);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->set('accountId', $account->id)
            ->set('amount', $amount)
            ->call('save')
            ->assertHasErrors('amount');

        $this->assertDatabaseCount('goal_account_allocations', 0);
    }

    public function test_exact_account_and_goal_capacity_is_accepted_but_excess_is_rejected(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['current_balance' => '100.00']);
        $firstGoal = Goal::factory()->for($user)->create(['target_amount' => '80.00']);
        $secondGoal = Goal::factory()->for($user)->create(['target_amount' => '50.00']);
        GoalAccountAllocation::factory()->for($firstGoal)->for($account)->create(['amount' => '60.00']);
        $this->actingAs($user);

        Livewire::test('pages::goals.allocations', ['goalId' => $secondGoal->id])
            ->set('accountId', $account->id)->set('amount', '40.00')->call('save')->assertHasNoErrors();

        $this->assertSame('100.00', $account->allocatedAmount());

        $thirdGoal = Goal::factory()->for($user)->create(['target_amount' => '10.00']);
        Livewire::test('pages::goals.allocations', ['goalId' => $thirdGoal->id])
            ->set('accountId', $account->id)->set('amount', '0.01')->call('save')->assertHasErrors('amount');
    }

    public function test_goal_target_capacity_accounts_for_other_accounts_and_edit_excludes_itself(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create(['target_amount' => '100.00']);
        $firstAccount = Account::factory()->for($user)->create(['name' => 'One', 'current_balance' => '100.00']);
        $secondAccount = Account::factory()->for($user)->create(['name' => 'Two', 'current_balance' => '100.00']);
        $first = GoalAccountAllocation::factory()->for($goal)->for($firstAccount)->create(['amount' => '60.00']);
        $second = GoalAccountAllocation::factory()->for($goal)->for($secondAccount)->create(['amount' => '20.00']);
        $this->actingAs($user);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('editAllocation', $second->id)->assertSet('maximumAmount', '40.00')
            ->set('amount', '40.00')->call('save')->assertHasNoErrors();
        $this->assertSame('40.00', $second->refresh()->amount);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('editAllocation', $first->id)->set('amount', '60.01')->call('save')->assertHasErrors('amount');
    }

    public function test_edit_excludes_current_allocation_from_account_capacity_and_allows_reduction(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['current_balance' => '300.00']);
        $firstGoal = Goal::factory()->for($user)->create(['name' => 'First', 'target_amount' => '300.00']);
        $secondGoal = Goal::factory()->for($user)->create(['name' => 'Second', 'target_amount' => '300.00']);
        GoalAccountAllocation::factory()->for($firstGoal)->for($account)->create(['amount' => '150.00']);
        $allocation = GoalAccountAllocation::factory()->for($secondGoal)->for($account)->create(['amount' => '60.00']);
        $this->actingAs($user);

        Livewire::test('pages::goals.allocations', ['goalId' => $secondGoal->id])
            ->call('editAllocation', $allocation->id)->assertSet('maximumAmount', '150.00')
            ->set('amount', '150.00')->call('save')->assertHasNoErrors();
        $this->assertSame('150.00', $allocation->refresh()->amount);

        Livewire::test('pages::goals.allocations', ['goalId' => $secondGoal->id])
            ->call('editAllocation', $allocation->id)->set('amount', '150.01')->call('save')->assertHasErrors('amount')
            ->set('amount', '25.00')->call('save')->assertHasNoErrors();
        $this->assertSame('25.00', $allocation->refresh()->amount);
    }

    public function test_duplicate_account_for_goal_is_rejected(): void
    {
        [$user, $goal, $account] = $this->financialContext();
        GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '10.00']);

        $this->expectException(ValidationException::class);
        app(SaveGoalAccountAllocation::class)->handle($user, $goal->id, $account->id, '5.00');
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_foreign_accounts_and_goals_are_inaccessible_without_admin_bypass(string $role): void
    {
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        $ownGoal = Goal::factory()->for($user)->create();
        $ownAccount = Account::factory()->for($user)->create();
        $foreignGoal = Goal::factory()->create();
        $foreignAccount = Account::factory()->create();
        $this->actingAs($user);

        $this->get(route('goals.allocations', $foreignGoal->id))->assertNotFound();
        Livewire::test('pages::goals.allocations', ['goalId' => $ownGoal->id])
            ->set('accountId', $foreignAccount->id)->set('amount', '1.00')->call('save')->assertNotFound();

        try {
            app(SaveGoalAccountAllocation::class)->handle($user, $foreignGoal->id, $ownAccount->id, '1.00');
            $this->fail('A foreign goal was accepted.');
        } catch (NotFoundHttpException) {
            $this->assertDatabaseCount('goal_account_allocations', 0);
        }
    }

    public function test_balance_reduction_preserves_allocation_warns_and_only_allows_reduction_or_removal(): void
    {
        [$user, $goal, $account] = $this->financialContext('100.00', '200.00');
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '80.00']);
        $this->actingAs($user);

        Livewire::test('pages::accounts.form', ['accountId' => $account->id])->set('current_balance', '50.00')->call('save')->assertHasNoErrors();

        $this->assertSame('80.00', $allocation->refresh()->amount);
        $this->assertSame('-30.00', $account->refresh()->availableAmount());
        $this->assertSame('30.00', $account->overallocatedAmount());
        $this->get(route('accounts.index'))->assertSeeText('Overallocated')->assertSeeText('R$ 30.00');

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->call('editAllocation', $allocation->id)->set('amount', '80.01')->call('save')->assertHasErrors('amount')
            ->set('amount', '40.00')->call('save')->assertHasNoErrors();
        $this->assertSame('40.00', $allocation->refresh()->amount);

        $otherGoal = Goal::factory()->for($user)->create(['target_amount' => '100.00']);
        $thirdGoal = Goal::factory()->for($user)->create(['target_amount' => '100.00']);
        GoalAccountAllocation::factory()->for($thirdGoal)->for($account)->create(['amount' => '20.00']);
        Livewire::test('pages::goals.allocations', ['goalId' => $otherGoal->id])
            ->set('accountId', $account->id)->set('amount', '1.00')->call('save')->assertHasErrors('amount');

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])->call('removeAllocation', $allocation->id)->assertHasNoErrors();
        $this->assertModelMissing($allocation);
    }

    public function test_inactive_account_preserves_allocation_and_allows_only_reduction_or_removal(): void
    {
        [$user, $goal, $account] = $this->financialContext();
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '50.00']);
        $account->is_active = false;
        $account->save();
        $this->actingAs($user);

        $otherGoal = Goal::factory()->for($user)->create(['target_amount' => '100.00']);
        Livewire::test('pages::goals.allocations', ['goalId' => $otherGoal->id])
            ->set('accountId', $account->id)->set('amount', '1.00')->call('save')->assertHasErrors('amount');
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->assertSeeText('Inactive')->call('editAllocation', $allocation->id)
            ->set('amount', '51.00')->call('save')->assertHasErrors('amount')
            ->set('amount', '25.00')->call('save')->assertHasNoErrors();

        $this->assertSame('25.00', $allocation->refresh()->amount);
        $this->assertFalse($account->refresh()->is_active);
        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])->call('removeAllocation', $allocation->id);
        $this->assertModelMissing($allocation);
    }

    public function test_goal_and_account_calculations_are_exact_and_overfunding_is_safe(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->for($user)->create(['target_amount' => '100.01']);
        $first = Account::factory()->for($user)->create(['name' => 'One', 'current_balance' => '1000.00']);
        $second = Account::factory()->for($user)->create(['name' => 'Two', 'current_balance' => '1000.00']);
        GoalAccountAllocation::factory()->for($goal)->for($first)->create(['amount' => '40.00']);
        GoalAccountAllocation::factory()->for($goal)->for($second)->create(['amount' => '60.01']);

        $this->assertSame('100.01', $goal->allocatedAmount());
        $this->assertSame('0.00', $goal->remainingAmount());
        $this->assertSame('100.0', $goal->progressPercentage());
        $this->assertSame('40.00', $first->allocatedAmount());
        $this->assertSame('960.00', $first->availableAmount());

        $goal->update(['target_amount' => '80.00']);
        $this->assertSame('100.01', $goal->refresh()->allocatedAmount());
        $this->assertSame('-20.01', $goal->remainingAmount());
        $this->assertSame('20.01', $goal->overfundedAmount());
        $this->assertSame('125.0', $goal->progressPercentage());
        $this->assertSame('100.0', $goal->visualProgressPercentage());
        $this->actingAs($user)->get(route('goals.allocations', $goal->id))->assertSeeText('Overfunded')->assertSeeText('20.01');
    }

    public function test_zero_balance_percentage_is_safe_and_cannot_create_allocation(): void
    {
        [$user, $goal, $account] = $this->financialContext('0.00', '100.00');
        $this->actingAs($user);

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])
            ->set('accountId', $account->id)->set('percentage', '20.0')
            ->assertSet('percentage', '0.0')->assertSet('amount', '0.00')
            ->call('save')->assertHasErrors('amount');
    }

    public function test_allocations_do_not_change_total_assets_and_removal_updates_totals(): void
    {
        [$user, $goal, $account] = $this->financialContext('300.00', '200.00');
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '75.00']);
        $this->actingAs($user);

        Livewire::test('pages::accounts.index')->assertSet('totalAssets', '300.00');
        $this->assertSame('225.00', $account->availableAmount());
        $this->assertSame('125.00', $goal->remainingAmount());

        Livewire::test('pages::goals.allocations', ['goalId' => $goal->id])->call('removeAllocation', $allocation->id);

        $this->assertSame('300.00', $account->refresh()->current_balance);
        $this->assertSame('300.00', $account->availableAmount());
        $this->assertSame('0.00', $goal->refresh()->allocatedAmount());
        $this->assertSame('0.0', $goal->progressPercentage());
    }

    public function test_another_user_cannot_edit_or_remove_allocation(): void
    {
        [$owner, $goal, $account] = $this->financialContext();
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create();
        $attacker = User::factory()->create();
        $attackerGoal = Goal::factory()->for($attacker)->create();
        $this->actingAs($attacker);

        Livewire::test('pages::goals.allocations', ['goalId' => $attackerGoal->id])->call('editAllocation', $allocation->id)->assertNotFound();
        Livewire::test('pages::goals.allocations', ['goalId' => $attackerGoal->id])->call('removeAllocation', $allocation->id)->assertNotFound();

        $this->assertModelExists($allocation);
        $this->assertNotSame($owner->id, $attacker->id);
    }

    public function test_whole_user_deletion_cascades_allocations(): void
    {
        [$user, $goal, $account] = $this->financialContext();
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');

        $this->assertModelMissing($allocation);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_goal_status_changes_never_modify_allocations(): void
    {
        [$user, $goal, $account] = $this->financialContext();
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '75.00']);
        $this->actingAs($user);

        Livewire::test('pages::goals.index')->call('setStatus', $goal->id, GoalStatus::Completed->value)->assertHasNoErrors();

        $this->assertSame(GoalStatus::Completed, $goal->refresh()->status);
        $this->assertSame('75.00', $allocation->refresh()->amount);
        $this->get(route('goals.allocations', $goal->id))->assertOk()->assertSeeText('Edit amount');
    }

    /** @return array{User, Goal, Account} */
    private function financialContext(string $balance = '300.00', string $target = '300.00'): array
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $goal = Goal::factory()->for($user)->create(['target_amount' => $target]);
        $account = Account::factory()->for($user)->create(['current_balance' => $balance]);

        return [$user, $goal, $account];
    }
}
