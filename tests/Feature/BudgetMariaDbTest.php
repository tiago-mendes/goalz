<?php

namespace Tests\Feature;

use App\Actions\SaveBudget;
use App\Actions\StopBudget;
use App\BudgetMode;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Runs against migrated MariaDB with every fixture rolled back. */
class BudgetMariaDbTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MariaDB to verify budget write locks.');
        }
    }

    public function test_budget_creation_locks_the_category_before_checking_for_overlaps(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        app(SaveBudget::class)->handle($user, $category, '100.00', BudgetMode::Recurring, '2026-09');

        $this->assertTrue(collect($statements)->contains(
            fn (string $sql): bool => str_contains($sql, 'from `expense_categories`') && str_contains($sql, 'for update'),
        ));
    }

    public function test_stopping_a_budget_uses_the_same_category_lock_order(): void
    {
        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->for($user)->create();
        $rule = app(SaveBudget::class)->handle($user, $category, '100.00', BudgetMode::Recurring, '2026-09');
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        app(StopBudget::class)->handle($rule);

        $categoryLock = collect($statements)->search(
            fn (string $sql): bool => str_contains($sql, 'from `expense_categories`') && str_contains($sql, 'for update'),
        );
        $ruleLock = collect($statements)->search(
            fn (string $sql): bool => str_contains($sql, 'from `budget_rules`') && str_contains($sql, 'for update'),
        );

        $this->assertIsInt($categoryLock);
        $this->assertIsInt($ruleLock);
        $this->assertTrue($categoryLock < $ruleLock);
    }
}
