<?php

namespace Tests\Feature;

use App\AccountType;
use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Expense;
use App\Models\FixedExpense;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountBalanceSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['0', '0.00'])]
    #[TestWith(['0.10', '0.10'])]
    public function test_account_creation_records_one_exact_initial_snapshot(string $submittedBalance, string $storedBalance): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->travelTo('2026-09-07 08:45:00');

        $account = $this->createAccount($user, $submittedBalance);

        $snapshot = $account->balanceSnapshots()->sole();
        $this->assertSame($storedBalance, $account->current_balance);
        $this->assertSame($storedBalance, $snapshot->balance);
        $this->assertTrue($snapshot->recorded_at->equalTo($account->balance_updated_at));
        $this->assertSame('2026-09-07 08:45:00', $snapshot->recorded_at->format('Y-m-d H:i:s'));
    }

    public function test_each_real_balance_change_appends_history_and_orders_newest_first(): void
    {
        $user = User::factory()->create(['currency' => 'BRL']);
        $this->actingAs($user);
        $this->travelTo('2026-09-07 08:00:00');
        $account = $this->createAccount($user, '1000');

        $this->travelTo('2026-09-07 09:00:00');
        Livewire::test('pages::accounts.form', ['accountId' => $account->id])
            ->set('current_balance', '2000.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->travelTo('2026-09-07 10:00:00');
        Livewire::test('pages::accounts.form', ['accountId' => $account->id])
            ->set('current_balance', '1500.00')
            ->call('save')
            ->assertHasNoErrors();

        $snapshots = $account->balanceSnapshots()->orderBy('id')->get();
        $this->assertSame(['1000.00', '2000.00', '1500.00'], $snapshots->pluck('balance')->all());
        $this->assertSame(
            ['2026-09-07 08:00:00', '2026-09-07 09:00:00', '2026-09-07 10:00:00'],
            $snapshots->pluck('recorded_at')->map->format('Y-m-d H:i:s')->all(),
        );
        $this->assertSame('1500.00', $account->refresh()->current_balance);
        $this->assertSame('2026-09-07 10:00:00', $account->balance_updated_at->format('Y-m-d H:i:s'));

        Livewire::test('pages::accounts.history', ['accountId' => $account->id])
            ->assertSeeInOrder(['Sep 7, 2026 10:00', 'R$ 1500.00', 'Sep 7, 2026 09:00', 'R$ 2000.00', 'Sep 7, 2026 08:00', 'R$ 1000.00']);
    }

    #[TestWith(['1000'])]
    #[TestWith(['1000.0'])]
    #[TestWith(['1000.00'])]
    public function test_equivalent_monetary_format_does_not_append_history_or_change_timestamp(string $submittedBalance): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->travelTo('2026-09-07 08:00:00');
        $account = $this->createAccount($user, '1000.00');
        $originalTimestamp = $account->balance_updated_at;

        $this->travelTo('2026-09-08 12:00:00');
        Livewire::test('pages::accounts.form', ['accountId' => $account->id])
            ->set('current_balance', $submittedBalance)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $account->balanceSnapshots()->count());
        $this->assertTrue($account->refresh()->balance_updated_at->equalTo($originalTimestamp));
    }

    public function test_non_balance_and_status_changes_preserve_history_and_balance_timestamp(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->createAccount($user, '1000.00');
        $originalTimestamp = $account->balance_updated_at;

        $this->travelTo($originalTimestamp->addDay());
        Livewire::test('pages::accounts.form', ['accountId' => $account->id])
            ->set('name', 'Renamed account')
            ->call('save')
            ->assertHasNoErrors();
        Livewire::test('pages::accounts.form', ['accountId' => $account->id])
            ->set('type', AccountType::Savings->value)
            ->call('save')
            ->assertHasNoErrors();
        Livewire::test('pages::accounts.index')->call('setActive', $account->id, false)->assertHasNoErrors();
        Livewire::test('pages::accounts.index')->call('setActive', $account->id, true)->assertHasNoErrors();

        $this->assertSame(1, $account->balanceSnapshots()->count());
        $this->assertTrue($account->refresh()->balance_updated_at->equalTo($originalTimestamp));
    }

    public function test_balance_reduction_below_allocations_appends_snapshot_without_changing_allocations(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->createAccount($user, '30000.00');
        $goal = Goal::factory()->for($user)->create(['target_amount' => '30000.00']);
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create(['amount' => '26000.00']);
        $originalCreatedAt = $allocation->created_at;
        $originalUpdatedAt = $allocation->updated_at;

        Livewire::test('pages::accounts.form', ['accountId' => $account->id])
            ->set('current_balance', '20000.00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['30000.00', '20000.00'], $account->balanceSnapshots()->orderBy('id')->pluck('balance')->all());
        $this->assertSame('26000.00', $allocation->refresh()->amount);
        $this->assertTrue($allocation->created_at->equalTo($originalCreatedAt));
        $this->assertTrue($allocation->updated_at->equalTo($originalUpdatedAt));
        $this->assertSame(1, GoalAccountAllocation::query()->count());
        $this->assertSame('6000.00', $account->refresh()->overallocatedAmount());
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_history_is_owner_scoped_without_admin_bypass(string $role): void
    {
        $owner = User::factory()->create(['currency' => 'BRL']);
        $this->actingAs($owner);
        $account = $this->createAccount($owner, '1234.56', 'Private savings');
        Livewire::test('pages::accounts.index')->call('setActive', $account->id, false)->assertHasNoErrors();

        $this->get(route('accounts.history', $account->id))
            ->assertOk()
            ->assertSeeText('Private savings')
            ->assertSeeText('Inactive')
            ->assertSeeText('R$ 1234.56');
        $this->get(route('accounts.index'))->assertSee('href="'.route('accounts.history', $account->id).'"', false);

        $viewer = User::factory()->create(['role' => UserRole::from($role)]);
        $this->actingAs($viewer);

        $this->get(route('accounts.history', $account->id))->assertNotFound();
        Livewire::test('pages::accounts.history', ['accountId' => $account->id])->assertNotFound();
    }

    public function test_history_route_requires_authentication_and_no_snapshot_write_routes_exist(): void
    {
        $account = Account::factory()->create();

        $this->get(route('accounts.history', $account->id))->assertRedirect(route('login'));
        $this->patch('/account-balance-snapshots/1')->assertNotFound();
        $this->delete('/account-balance-snapshots/1')->assertNotFound();
    }

    public function test_snapshot_models_cannot_be_changed_or_deleted(): void
    {
        $snapshot = AccountBalanceSnapshot::factory()->create(['balance' => '100.00']);

        try {
            $snapshot->balance = '200.00';
            $snapshot->save();
            $this->fail('An existing snapshot was updated.');
        } catch (LogicException $exception) {
            $this->assertSame('Account balance snapshots are immutable.', $exception->getMessage());
        }

        try {
            $snapshot->refresh()->delete();
            $this->fail('An existing snapshot was deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('Account balance snapshots are immutable.', $exception->getMessage());
        }

        $this->assertSame('100.00', $snapshot->refresh()->balance);
    }

    public function test_history_uses_snapshot_id_as_the_descending_timestamp_tiebreaker(): void
    {
        $account = Account::factory()->create();
        $first = AccountBalanceSnapshot::factory()->for($account)->create([
            'balance' => '100.00',
            'recorded_at' => '2026-09-07 10:00:00',
        ]);
        $second = AccountBalanceSnapshot::factory()->for($account)->create([
            'balance' => '200.00',
            'recorded_at' => '2026-09-07 10:00:00',
        ]);
        $this->actingAs($account->user);

        $snapshots = Livewire::test('pages::accounts.history', ['accountId' => $account->id])->get('snapshots');

        $this->assertSame([$second->id, $first->id], $snapshots->pluck('id')->all());
    }

    public function test_migration_backfills_each_existing_account_once_with_its_known_timestamp(): void
    {
        $first = Account::factory()->create([
            'current_balance' => '12345.67',
            'balance_updated_at' => '2026-08-01 12:34:56',
        ]);
        $second = Account::factory()->create([
            'current_balance' => '0.00',
            'balance_updated_at' => '2026-08-02 07:00:00',
        ]);
        $migration = require database_path('migrations/2026_09_07_223024_create_account_balance_snapshots_table.php');
        $migration->down();

        $migration->up();

        $this->assertDatabaseCount('account_balance_snapshots', 2);
        $this->assertDatabaseHas('account_balance_snapshots', [
            'account_id' => $first->id,
            'balance' => '12345.67',
            'recorded_at' => '2026-08-01 12:34:56',
        ]);
        $this->assertDatabaseHas('account_balance_snapshots', [
            'account_id' => $second->id,
            'balance' => '0',
            'recorded_at' => '2026-08-02 07:00:00',
        ]);
    }

    public function test_whole_user_deletion_cleans_snapshots_with_other_financial_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->createAccount($user, '500.00');
        $snapshot = $account->balanceSnapshots()->sole();
        $goal = Goal::factory()->for($user)->create();
        $allocation = GoalAccountAllocation::factory()->for($goal)->for($account)->create();
        $expense = Expense::factory()->for($user)->create(['payment_account_id' => $account->id]);
        $fixedExpense = FixedExpense::factory()->for($user)->create(['payment_account_id' => $account->id]);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($snapshot);
        $this->assertModelMissing($allocation);
        $this->assertModelMissing($expense);
        $this->assertModelMissing($fixedExpense);
        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    private function createAccount(User $user, string $balance, string $name = 'Savings'): Account
    {
        Livewire::test('pages::accounts.form')
            ->set([
                'name' => $name,
                'type' => AccountType::Checking->value,
                'current_balance' => $balance,
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('accounts.index'));

        return $user->accounts()->where('name', $name)->sole();
    }
}
