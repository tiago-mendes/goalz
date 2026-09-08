<?php

namespace Tests\Feature;

use App\AccountType;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\GoalMembership;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_their_accounts_and_navigation(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create(['name' => 'My checking']);
        Account::factory()->create(['name' => 'Private foreign account']);
        $this->actingAs($user);

        $this->get(route('accounts.index'))->assertOk()->assertSeeText('My checking')->assertDontSeeText('Private foreign account');
        $this->get(route('accounts.create'))->assertOk();
        $this->get(route('dashboard'))->assertSee('href="'.route('accounts.index').'"', false);
    }

    public function test_owner_can_create_an_active_account_with_exact_balance(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->travelTo('2026-09-07 10:00:00');

        Livewire::test('pages::accounts.form')->set([
            'name' => '  Itaú Checking  ',
            'type' => AccountType::Checking->value,
            'current_balance' => '8000.00',
        ])->call('save')->assertHasNoErrors()->assertRedirect(route('accounts.index'));

        $account = $user->accounts()->sole();
        $this->assertSame('Itaú Checking', $account->name);
        $this->assertSame(AccountType::Checking, $account->type);
        $this->assertSame('8000.00', $account->refresh()->current_balance);
        $this->assertTrue($account->is_active);
        $this->assertSame('2026-09-07 10:00:00', $account->balance_updated_at->format('Y-m-d H:i:s'));
    }

    #[TestWith(['-0.01', 'regex'])]
    #[TestWith(['10000000000000.00', 'regex'])]
    #[TestWith(['1.001', 'regex'])]
    #[TestWith(['unsupported', 'in'])]
    public function test_invalid_account_values_are_rejected(string $value, string $rule): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $component = Livewire::test('pages::accounts.form')->set('name', 'Account');
        $field = $rule === 'in' ? 'type' : 'current_balance';
        $component->set($field, $value)->call('save')->assertHasErrors([$field => $rule]);
        $this->assertSame(0, $user->accounts()->count());
    }

    public function test_zero_balance_and_same_name_for_different_users_are_allowed_but_duplicates_are_not(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        Account::factory()->for($first)->create(['name' => 'Cash', 'current_balance' => '0.00']);
        $this->actingAs($first);

        Livewire::test('pages::accounts.form')->set(['name' => 'Cash', 'current_balance' => '1.00'])->call('save')->assertHasErrors(['name' => 'unique']);
        $this->actingAs($second);
        Livewire::test('pages::accounts.form')->set(['name' => 'Cash', 'current_balance' => '0.00'])->call('save')->assertHasNoErrors();
        $this->assertSame('0.00', $second->accounts()->sole()->current_balance);
    }

    public function test_owner_can_edit_without_changing_balance_timestamp_unless_balance_changes(): void
    {
        $account = Account::factory()->create(['name' => 'Old name', 'type' => AccountType::Checking, 'current_balance' => '10.00']);
        $this->actingAs($account->user);
        $originalUpdatedAt = $account->balance_updated_at;

        $this->travelTo($originalUpdatedAt->addDay());
        Livewire::test('pages::accounts.form', ['accountId' => $account->id])->set('name', 'New name')->call('save')->assertHasNoErrors();
        $this->assertTrue($account->refresh()->balance_updated_at->equalTo($originalUpdatedAt));

        Livewire::test('pages::accounts.form', ['accountId' => $account->id])->set('type', AccountType::Savings->value)->call('save')->assertHasNoErrors();
        $this->assertTrue($account->refresh()->balance_updated_at->equalTo($originalUpdatedAt));

        $this->travelTo($originalUpdatedAt->addDays(2));
        Livewire::test('pages::accounts.form', ['accountId' => $account->id])->set('current_balance', '10.01')->call('save')->assertHasNoErrors();
        $this->assertTrue($account->refresh()->balance_updated_at->equalTo(now()));
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_foreign_accounts_are_inaccessible_even_to_admins(string $role): void
    {
        $foreign = Account::factory()->create(['name' => 'Private account']);
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        $this->actingAs($user);

        $this->get(route('accounts.index'))->assertDontSeeText('Private account');
        $this->get(route('accounts.edit', $foreign->id))->assertNotFound();
        Livewire::test('pages::accounts.form', ['accountId' => $foreign->id])->assertNotFound();
        Livewire::test('pages::accounts.index')->call('setActive', $foreign->id, false)->assertNotFound();
        $this->assertTrue($foreign->refresh()->is_active);
    }

    public function test_active_status_preserves_balance_and_balance_timestamp_and_total_assets_excludes_inactive_accounts(): void
    {
        $user = User::factory()->create();
        $active = Account::factory()->for($user)->create(['name' => 'Checking', 'current_balance' => '8000.00']);
        $inactive = Account::factory()->for($user)->inactive()->create(['name' => 'Old account', 'current_balance' => '5000.00']);
        $updatedAt = $inactive->balance_updated_at;
        $this->actingAs($user);

        Livewire::test('pages::accounts.index')->assertSet('totalAssets', '8000.00')->call('setActive', $inactive->id, true)->assertHasNoErrors()->assertSet('totalAssets', '13000.00');
        $inactive->refresh();
        $this->assertSame('5000.00', $inactive->current_balance);
        $this->assertTrue($inactive->balance_updated_at->equalTo($updatedAt));
        Livewire::test('pages::accounts.index')->call('setActive', $active->id, false)->assertHasNoErrors()->assertSet('totalAssets', '5000.00');
    }

    public function test_account_summary_cards_use_active_owned_accounts_and_exact_goal_allocations(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $other = User::factory()->create();
        $first = Account::factory()->for($user)->create(['current_balance' => '1000.10']);
        $second = Account::factory()->for($user)->create(['current_balance' => '2000.20']);
        $inactive = Account::factory()->for($user)->inactive()->create(['current_balance' => '9000.00']);
        $foreign = Account::factory()->for($other)->create(['current_balance' => '8000.00']);
        $personalGoal = Goal::factory()->for($user)->create();
        $sharedGoal = Goal::factory()->for($other)->create();
        GoalMembership::factory()->for($sharedGoal)->for($user)->accepted()->create();
        GoalAccountAllocation::factory()->for($personalGoal)->for($first)->create(['amount' => '400.04']);
        GoalAccountAllocation::factory()->for($sharedGoal)->for($first)->create(['amount' => '50.01']);
        GoalAccountAllocation::factory()->for($sharedGoal)->for($second)->create(['amount' => '2100.30']);
        GoalAccountAllocation::factory()->for($personalGoal)->for($inactive)->create(['amount' => '9000.00']);
        GoalAccountAllocation::factory()->for($personalGoal)->for($foreign)->create(['amount' => '8000.00']);
        $this->actingAs($user);

        Livewire::test('pages::accounts.index')
            ->assertSet('totalAssets', '3000.30')
            ->assertSet('totalAllocated', '2550.35')
            ->assertSet('totalAvailable', '449.95')
            ->assertSeeText(['Total Assets', 'Total Allocated', 'Total Available', 'R$ 3000.30', 'R$ 2550.35', 'R$ 449.95', 'Overallocated']);
    }

    public function test_policy_allows_only_ownership_and_never_deletion(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $own = Account::factory()->for($user)->create();
        $foreign = Account::factory()->create();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', Account::class));
        $this->assertTrue($gate->allows('create', Account::class));
        $this->assertTrue($gate->allows('update', $own));
        $this->assertSame(404, $gate->inspect('update', $foreign)->status());
        $this->assertFalse($gate->allows('delete', $own));
    }

    public function test_user_deletion_cascades_owned_accounts_and_no_delete_action_is_exposed(): void
    {
        $account = Account::factory()->create();
        $this->actingAs($account->user);

        $this->get(route('accounts.index'))->assertDontSeeText('Delete');
        $this->delete('/accounts/'.$account->id)->assertNotFound();
        $this->assertFalse(method_exists(Livewire::test('pages::accounts.index')->instance(), 'delete'));

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
    }

    public function test_database_unique_constraint_remains_an_integrity_boundary(): void
    {
        $account = Account::factory()->create(['name' => 'Cash']);

        $this->expectException(UniqueConstraintViolationException::class);
        Account::factory()->for($account->user)->create(['name' => 'Cash']);
    }
}
