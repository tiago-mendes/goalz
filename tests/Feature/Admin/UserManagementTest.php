<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_all_pages_and_navigation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $this->get(route('admin.index'))->assertRedirect('/admin/users');
        $this->get(route('admin.users.index'))->assertOk()->assertSeeText($admin->email);
        $this->get(route('admin.users.create'))->assertOk();
        $this->get(route('admin.users.edit', $admin))->assertOk();
        $this->get(route('dashboard'))->assertSee('href="'.route('admin.users.index').'"', false);
        $this->assertFalse(Gate::allows('view-private-finances'));
    }

    public function test_normal_users_cannot_access_any_admin_page_or_navigation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['admin.index', 'admin.users.index', 'admin.users.create', 'admin.users.edit'] as $route) {
            $this->get(route($route, $route === 'admin.users.edit' ? $user : []))->assertForbidden();
        }
        $this->get(route('dashboard'))->assertDontSee('href="'.route('admin.users.index').'"', false);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $user = User::factory()->create();
        foreach (['admin.index', 'admin.users.index', 'admin.users.create', 'admin.users.edit'] as $route) {
            $this->get(route($route, $route === 'admin.users.edit' ? $user : []))->assertRedirect(route('login'));
        }
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_admin_can_create_accounts_with_hashed_passwords(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test('pages::admin.users.form')
            ->set('name', 'New Account')->set('email', 'new@example.com')
            ->set('password', 'safe-password-123')->set('password_confirmation', 'safe-password-123')
            ->set('role', $role)->set('currency', 'USD')
            ->call('save', ['default_monthly_income' => '1234.50'])->assertHasNoErrors()->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame(8, $user->expenseCategories()->count());
        $this->assertSame($role, $user->role->value);
        $this->assertTrue($user->is_active);
        $this->assertSame('USD', $user->currency);
        $this->assertNull($user->default_monthly_income);
        $this->assertTrue(Hash::check('safe-password-123', $user->password));
    }

    public function test_admin_can_edit_account_fields_without_changing_password_or_income(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $user = User::factory()->create(['default_monthly_income' => '987654.32']);
        $password = $user->password;

        Livewire::test('pages::admin.users.form', ['user' => $user])
            ->set('name', 'Updated Name')->set('email', 'updated@example.com')
            ->set('currency', 'EUR')
            ->call('save', ['default_monthly_income' => '0'])->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('updated@example.com', $user->email);
        $this->assertSame('EUR', $user->currency);
        $this->assertSame('987654.32', $user->default_monthly_income);
        $this->assertSame($password, $user->password);
        $this->assertNull($user->email_verified_at);
    }

    #[TestWith(['user', true, 'admin', false])]
    #[TestWith(['admin', false, 'user', true])]
    public function test_admin_can_change_another_accounts_role_and_status(string $oldRole, bool $oldActive, string $role, bool $active): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $user = User::factory()->create(['role' => $oldRole, 'is_active' => $oldActive]);

        Livewire::test('pages::admin.users.form', ['user' => $user])
            ->set('role', $role)->set('is_active', $active)->call('save')->assertHasNoErrors();

        $this->assertSame($role, $user->refresh()->role->value);
        $this->assertSame($active, $user->is_active);
    }

    #[TestWith(['role', 'user'])]
    #[TestWith(['is_active', false])]
    public function test_last_active_admin_cannot_demote_or_deactivate_self(string $field, mixed $value): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test('pages::admin.users.form', ['user' => $admin])
            ->set($field, $value)->call('save')->assertHasErrors([$field]);

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
        $this->assertTrue($admin->is_active);
    }

    public function test_normal_users_cannot_mount_admin_components_directly(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::admin.users.index')->assertForbidden();
        Livewire::test('pages::admin.users.form')->assertForbidden();
        Livewire::test('pages::admin.users.form', ['user' => $user])->assertForbidden();
    }

    #[TestWith(['role', 'user'])]
    #[TestWith(['is_active', false])]
    public function test_revoked_admin_cannot_save_from_an_open_form(string $field, mixed $value): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        $this->actingAs($admin);
        $component = Livewire::test('pages::admin.users.form', ['user' => $user]);
        $admin->{$field} = $value;
        $admin->save();

        $component->call('save')->assertForbidden();
        $this->assertSame(UserRole::User, $user->refresh()->role);
    }

    #[TestWith(['role', 'owner'])]
    #[TestWith(['is_active', 'invalid'])]
    #[TestWith(['currency', 'USDD'])]
    #[TestWith(['currency', 'usd'])]
    #[TestWith(['email', 'invalid'])]
    public function test_invalid_account_values_are_not_saved(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $user = User::factory()->create();
        $original = $user->refresh()->getAttributes();

        Livewire::test('pages::admin.users.form', ['user' => $user])
            ->set($field, $value)->call('save')->assertHasErrors([$field]);

        $this->assertSame($original, $user->refresh()->getAttributes());
    }

    public function test_duplicate_email_and_unconfirmed_password_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test('pages::admin.users.form')->set('name', 'New User')
            ->set('email', $admin->email)->set('password', 'safe-password-123')
            ->call('save')->assertHasErrors(['email', 'password']);

        $this->assertDatabaseCount('users', 1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_admin_cannot_inject_default_income_into_create_or_edit_state(bool $editing): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $user = User::factory()->create(['default_monthly_income' => '987654.32']);
        $component = Livewire::test('pages::admin.users.form', $editing ? ['user' => $user] : []);

        try {
            $component->set('default_monthly_income', '1.00');
            $this->fail('Financial data must not be accepted as admin form state.');
        } catch (PublicPropertyNotFoundException) {
            $this->assertSame('987654.32', $user->refresh()->default_monthly_income);
            $this->assertDatabaseCount('users', 2);
        }
    }

    public function test_list_exposes_only_account_summary_and_form_never_loads_secrets(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['default_monthly_income' => '987654.32']);
        $this->actingAs($admin);

        $this->get(route('admin.users.index'))->assertOk()
            ->assertDontSee('987654.32')->assertDontSee($user->password)->assertDontSee($user->remember_token);
        $this->get(route('admin.users.edit', $user))->assertOk()
            ->assertDontSee('987654.32')->assertDontSee('default_monthly_income')
            ->assertDontSeeText('Default monthly income')
            ->assertDontSee($user->password)->assertDontSee($user->remember_token)
            ->assertDontSeeText('Expenses')->assertDontSeeText('Goal contributions');
    }
}
