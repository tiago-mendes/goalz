<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_active_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_users_cannot_authenticate_with_a_valid_password(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'is_active' => true,
            'remember' => true,
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => __('auth.failed')])
            ->assertSessionMissing(auth()->guard()->getName());

        $this->assertGuest();
    }

    public function test_existing_session_is_revoked_when_the_user_becomes_inactive(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);
        $user->is_active = false;
        $user->save();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_active_user_check_is_applied_to_livewire_updates(): void
    {
        $route = app('router')->getRoutes()->getByName('default-livewire.update');

        $this->assertNotNull($route);
        $this->assertContains(EnsureUserIsActive::class, app('router')->gatherRouteMiddleware($route));
    }

    public function test_unknown_users_cannot_authenticate(): void
    {
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'missing@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => __('auth.failed')])
            ->assertSessionMissing(auth()->guard()->getName());

        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrorsIn('email')
            ->assertSessionMissing(auth()->guard()->getName());

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }
}
