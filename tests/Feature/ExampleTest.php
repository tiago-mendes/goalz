<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSeeText('Goalz')
            ->assertSeeText('There is always one more hill to climb')
            ->assertDontSeeText('Financial Goals')
            ->assertDontSeeText('Expense Categories')
            ->assertDontSeeText('Monthly Income Tracking')
            ->assertDontSeeText('Manage your personal finances')
            ->assertDontSee('<article', false)
            ->assertDontSee('<footer', false)
            ->assertSee('data-goalz-theme="sage"', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertDontSee('href="'.route('dashboard').'"', false)
            ->assertDontSeeText('Laracasts');
    }

    public function test_signed_in_users_see_dashboard_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk()
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('href="'.route('login').'"', false)
            ->assertDontSee('href="'.route('register').'"', false);
    }
}
