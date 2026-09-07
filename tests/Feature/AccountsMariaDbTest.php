<?php

namespace Tests\Feature;

use App\Models\Account;
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
        $account = Account::factory()->create(['current_balance' => '9999999999999.99']);
        Account::factory()->for($account->user)->create(['name' => 'Second account', 'current_balance' => '9999999999999.99']);

        $this->assertSame('9999999999999.99', $account->refresh()->getRawOriginal('current_balance'));
        $this->actingAs($account->user);
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
}
