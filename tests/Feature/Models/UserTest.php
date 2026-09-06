<?php

namespace Tests\Feature\Models;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_receive_database_account_defaults(): void
    {
        $user = User::factory()->create()->refresh();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame('BRL', $user->currency);
        $this->assertNull($user->default_monthly_income);
    }

    public function test_admin_role_and_inactive_status_round_trip(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => false,
        ])->refresh();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertFalse($user->is_active);
    }

    public function test_income_round_trips_and_serializes_as_a_decimal_string(): void
    {
        $user = User::factory()->create(['default_monthly_income' => '123456.70'])->refresh();

        $this->assertSame('123456.70', $user->default_monthly_income);
        $this->assertSame('123456.70', $user->toArray()['default_monthly_income']);
    }

    public function test_income_cast_preserves_precision_at_the_column_limit(): void
    {
        $user = User::factory()->make(['default_monthly_income' => '9999999999999.99']);

        $this->assertSame('9999999999999.99', $user->default_monthly_income);
        $this->assertSame('9999999999999.99', $user->toArray()['default_monthly_income']);
    }
}
