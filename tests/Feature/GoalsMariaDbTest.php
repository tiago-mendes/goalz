<?php

namespace Tests\Feature;

use App\Models\Goal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalsMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify goal decimal storage and constraints.');
        }
    }

    public function test_maximum_target_round_trips_exactly(): void
    {
        $goal = Goal::factory()->create(['target_amount' => '9999999999999.99']);

        $this->assertSame('9999999999999.99', $goal->refresh()->getRawOriginal('target_amount'));
    }

    public function test_database_rejects_non_positive_target(): void
    {
        $this->expectException(QueryException::class);
        Goal::factory()->create(['target_amount' => '0.00']);
    }

    public function test_database_enforces_case_insensitive_owner_name_uniqueness_and_foreign_key(): void
    {
        $goal = Goal::factory()->create(['name' => 'Checking']);
        $this->expectException(QueryException::class);
        Goal::factory()->for($goal->user)->create(['name' => 'checking']);
    }

    public function test_database_rejects_a_missing_owner(): void
    {
        $goal = Goal::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('goals')->where('id', $goal->id)->update(['user_id' => 0]);
    }
}
