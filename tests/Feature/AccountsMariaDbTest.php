<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Runs against migrated MariaDB with every fixture rolled back. */
class AccountsMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify account decimal storage and constraints.');
        }
    }

    public function test_maximum_decimal_round_trips_exactly(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test('pages::accounts.form')
            ->set(['name' => 'Maximum account', 'current_balance' => '9999999999999.99'])
            ->call('save')
            ->assertHasNoErrors();
        $account = $user->accounts()->sole();
        Account::factory()->for($account->user)->create(['name' => 'Second account', 'current_balance' => '9999999999999.99']);

        $this->assertSame('9999999999999.99', $account->refresh()->getRawOriginal('current_balance'));
        $this->assertSame('9999999999999.99', $account->balanceSnapshots()->sole()->getRawOriginal('balance'));
        Livewire::test('pages::accounts.index')->assertSet('totalAssets', '19999999999999.98');
    }

    public function test_database_rejects_negative_balance(): void
    {
        $this->expectException(QueryException::class);
        Account::factory()->create(['current_balance' => '-0.01']);
    }

    public function test_foreign_key_and_case_insensitive_name_constraint_hold(): void
    {
        $account = Account::factory()->create(['name' => 'Checking']);
        $this->expectException(QueryException::class);
        Account::factory()->for($account->user)->create(['name' => 'checking']);
    }

    public function test_database_rejects_a_missing_owner(): void
    {
        $account = Account::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('accounts')->where('id', $account->id)->update(['user_id' => 0]);
    }

    public function test_snapshot_decimal_values_round_trip_exactly_and_allow_same_timestamp(): void
    {
        $account = Account::factory()->create();
        $recordedAt = now();
        $maximum = AccountBalanceSnapshot::factory()->for($account)->create([
            'balance' => '9999999999999.99',
            'recorded_at' => $recordedAt,
        ]);
        $zero = AccountBalanceSnapshot::factory()->for($account)->create([
            'balance' => '0.00',
            'recorded_at' => $recordedAt,
        ]);

        $this->assertSame('9999999999999.99', $maximum->refresh()->getRawOriginal('balance'));
        $this->assertSame('0.00', $zero->refresh()->getRawOriginal('balance'));
        $this->assertSame(2, $account->balanceSnapshots()->count());
    }

    public function test_balance_change_appends_an_exact_snapshot(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test('pages::accounts.form')
            ->set(['name' => 'Savings', 'current_balance' => '1000.10'])
            ->call('save')
            ->assertHasNoErrors();
        $account = $user->accounts()->sole();

        Livewire::test('pages::accounts.form', ['accountId' => $account->id])
            ->set('current_balance', '2000.20')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['1000.10', '2000.20'], $account->balanceSnapshots()->orderBy('id')->pluck('balance')->all());
        $this->assertSame('2000.20', $account->refresh()->getRawOriginal('current_balance'));
    }

    public function test_snapshot_foreign_key_rejects_missing_account_and_cascades_with_user_deletion(): void
    {
        $snapshot = AccountBalanceSnapshot::factory()->create();
        $user = $snapshot->account->user;

        $user->delete();

        $this->assertModelMissing($snapshot);

        $this->expectException(QueryException::class);
        DB::table('account_balance_snapshots')->insert([
            'account_id' => 0,
            'balance' => '1.00',
            'recorded_at' => now(),
        ]);
    }

    public function test_database_rejects_negative_snapshot_balance(): void
    {
        $this->expectException(QueryException::class);
        AccountBalanceSnapshot::factory()->create(['balance' => '-0.01']);
    }
}
