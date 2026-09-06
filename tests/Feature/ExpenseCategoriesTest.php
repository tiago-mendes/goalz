<?php

namespace Tests\Feature;

use App\Actions\ProvisionExpenseCategories;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExpenseCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_their_categories_including_inactive_ones_and_navigation(): void
    {
        $user = User::factory()->create();
        ExpenseCategory::factory()->for($user)->inactive()->create(['name' => 'My archived category']);
        ExpenseCategory::factory()->create(['name' => 'Private foreign category']);
        $this->actingAs($user);

        $this->get(route('expense-categories.index'))->assertOk()
            ->assertSeeText('My archived category')->assertSeeText('Inactive')
            ->assertDontSeeText('Private foreign category');
        $this->get(route('expense-categories.create'))->assertOk();
        $this->get(route('dashboard'))->assertSee('href="'.route('expense-categories.index').'"', false);
    }

    public function test_guests_are_redirected_from_every_category_page(): void
    {
        $category = ExpenseCategory::factory()->create();

        foreach (['expense-categories.index', 'expense-categories.create', 'expense-categories.edit'] as $route) {
            $this->get(route($route, $route === 'expense-categories.edit' ? $category->id : []))->assertRedirect(route('login'));
        }
        Livewire::test('pages::expense-categories.index')->assertForbidden();
        Livewire::test('pages::expense-categories.form')->assertForbidden();
    }

    public function test_backfill_provisions_existing_users_and_is_idempotent_without_overwriting_categories(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        User::factory()->count(199)->create();
        $other = User::factory()->create(['is_active' => false]);
        $food = ExpenseCategory::factory()->for($user)->inactive()->create([
            'name' => 'Food', 'icon' => 'tag', 'color' => '#123456',
            'created_at' => now()->subYear(), 'updated_at' => now()->subDay(),
        ]);
        $originalFood = $food->refresh()->getRawOriginal();
        $timestamp = now()->toDateTimeString();
        $migration = require database_path('migrations/2026_09_06_062556_provision_existing_users_expense_categories.php');

        $migration->up();
        $this->travel(1)->day();
        $migration->up();
        app(ProvisionExpenseCategories::class)->handle($user);

        $this->assertSame(201, DB::table('expense_categories')->distinct()->count('user_id'));
        $this->assertSame(1608, DB::table('expense_categories')->count());
        $this->assertSame($originalFood, $food->refresh()->getRawOriginal());
        foreach ([
            ['Food', 'shopping-cart', '#F97316'],
            ['Transport', 'truck', '#3B82F6'],
            ['Housing', 'home', '#8B5CF6'],
            ['Health', 'heart', '#EF4444'],
            ['Leisure', 'puzzle-piece', '#EC4899'],
            ['Education', 'academic-cap', '#14B8A6'],
            ['Subscriptions', 'arrow-path', '#EAB308'],
            ['Other', 'tag', '#64748B'],
        ] as [$name, $icon, $color]) {
            $this->assertDatabaseHas('expense_categories', [
                'user_id' => $other->id, 'name' => $name, 'icon' => $icon, 'color' => $color,
                'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
        }
        $this->assertEqualsCanonicalizing(['Food', 'Transport', 'Housing', 'Health', 'Leisure', 'Education', 'Subscriptions', 'Other'], $user->expenseCategories()->pluck('name')->all());
        $this->assertSame(8, $other->expenseCategories()->count());
        $this->assertFalse($food->refresh()->is_active);
        $this->assertSame('#123456', $food->color);
        $this->assertTrue($other->expenseCategories()->where('name', 'Food')->sole()->is_active);
        $migration->down();
        $this->assertSame(8, $user->expenseCategories()->count());
        $this->assertSame(1608, DB::table('expense_categories')->count());
    }

    public function test_user_can_create_trimmed_category_with_safe_defaults(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::expense-categories.form')->set('name', '  Pets  ')->set('icon', '')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('expense-categories.index'));

        $category = $user->expenseCategories()->sole();
        $this->assertSame('Pets', $category->name);
        $this->assertSame('tag', $category->icon);
        $this->assertSame('#64748B', $category->color);
        $this->assertTrue($category->is_active);
        $this->assertTrue($category->user->is($user));
    }

    public function test_user_can_edit_their_category_and_keep_its_name(): void
    {
        $category = ExpenseCategory::factory()->create(['name' => 'Pets']);
        $this->actingAs($category->user);
        $this->get(route('expense-categories.edit', $category->id))->assertOk()->assertSee('Pets');

        Livewire::test('pages::expense-categories.form', ['categoryId' => $category->id])
            ->set('icon', 'heart')->set('color', '#abcdef')->set('is_active', false)
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id, 'name' => 'Pets', 'icon' => 'heart', 'color' => '#ABCDEF', 'is_active' => false]);
    }

    public function test_icon_picker_renders_options_and_saves_a_new_icon(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test('pages::expense-categories.form')->assertSet('icon', 'tag');
        foreach (ExpenseCategory::ICONS as $label) {
            $component->assertSee('aria-label="'.$label.'"', false);
        }
        $component->set('name', 'Travel')->set('icon', 'paper-airplane')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('expense-categories.index'));

        $this->assertSame('paper-airplane', $user->expenseCategories()->sole()->icon);
    }

    public function test_icon_picker_marks_the_saved_icon_and_updates_selection(): void
    {
        $category = ExpenseCategory::factory()->create(['icon' => 'gift']);
        $this->actingAs($category->user);

        $component = Livewire::test('pages::expense-categories.form', ['categoryId' => $category->id])
            ->assertSet('icon', 'gift');
        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Gifts"[^>]*aria-pressed="true"/s', $component->html());
        $component->set('icon', 'wifi');
        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Internet"[^>]*aria-pressed="true"/s', $component->html());
        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Gifts"[^>]*aria-pressed="false"/s', $component->html());
        $component->call('save')->assertHasNoErrors();

        $this->assertSame('wifi', $category->refresh()->icon);
    }

    #[TestWith([true, false])]
    #[TestWith([false, true])]
    public function test_user_can_change_active_status(bool $before, bool $after): void
    {
        $category = ExpenseCategory::factory()->create(['is_active' => $before]);
        $this->actingAs($category->user);

        Livewire::test('pages::expense-categories.index')->call('setActive', $category->id, $after)->assertHasNoErrors();

        $this->assertSame($after, $category->refresh()->is_active);
        $this->assertModelExists($category);
    }

    public function test_duplicate_name_is_rejected_on_create_and_edit_for_the_same_user(): void
    {
        $category = ExpenseCategory::factory()->create(['name' => 'Pets']);
        $second = ExpenseCategory::factory()->for($category->user)->create(['name' => 'Travel']);
        $this->actingAs($category->user);

        Livewire::test('pages::expense-categories.form')->set('name', ' Pets ')->call('save')->assertHasErrors(['name' => 'unique']);
        Livewire::test('pages::expense-categories.form', ['categoryId' => $second->id])
            ->set('name', 'Pets')->call('save')->assertHasErrors(['name' => 'unique']);

        $this->assertSame(2, $category->user->expenseCategories()->count());
        $this->assertSame('Travel', $second->refresh()->name);
    }

    public function test_database_rejects_duplicate_names_for_one_owner(): void
    {
        $category = ExpenseCategory::factory()->create(['name' => 'Pets']);

        $this->expectException(UniqueConstraintViolationException::class);
        ExpenseCategory::factory()->for($category->user)->create(['name' => 'Pets']);
    }

    public function test_same_name_is_allowed_for_different_users(): void
    {
        ExpenseCategory::factory()->create(['name' => 'Pets']);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::expense-categories.form')->set('name', 'Pets')->call('save')->assertHasNoErrors();

        $this->assertSame('Pets', $user->expenseCategories()->sole()->name);
    }

    #[TestWith(['name', '   ', 'required'])]
    #[TestWith(['icon', '<svg onload=alert(1)>', 'in'])]
    #[TestWith(['icon', '../../secret', 'in'])]
    #[TestWith(['color', 'red; background: url(https://example.com)', 'regex'])]
    #[TestWith(['color', '#FFF', 'regex'])]
    #[TestWith(['color', '#GGGGGG', 'regex'])]
    #[TestWith(['color', '', 'required'])]
    #[TestWith(['is_active', 'invalid', 'boolean'])]
    public function test_invalid_fields_are_rejected_without_writes(string $field, mixed $value, string $rule): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::expense-categories.form')->set('name', 'Pets')->set($field, $value)
            ->call('save')->assertHasErrors([$field => $rule]);

        $this->assertSame(0, $user->expenseCategories()->count());
    }

    public function test_name_length_is_limited_to_one_hundred_characters(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::expense-categories.form')->set('name', str_repeat('a', 101))
            ->call('save')->assertHasErrors(['name' => 'max']);
        $this->assertSame(0, $user->expenseCategories()->count());
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_other_owners_categories_are_not_found_even_for_admins(string $role): void
    {
        $category = ExpenseCategory::factory()->create(['name' => 'Private category']);
        $this->actingAs(User::factory()->create(['role' => UserRole::from($role)]));

        $this->get(route('expense-categories.index'))->assertDontSeeText('Private category');
        $this->get(route('expense-categories.edit', $category->id))->assertNotFound();
        $this->get(route('expense-categories.edit', 999999))->assertNotFound();
        Livewire::test('pages::expense-categories.form', ['categoryId' => $category->id])->assertNotFound();
        Livewire::test('pages::expense-categories.index')->call('setActive', $category->id, false)->assertNotFound();

        $this->assertSame('Private category', $category->refresh()->name);
        $this->assertTrue($category->is_active);
    }

    public function test_locked_category_id_cannot_be_manipulated(): void
    {
        $category = ExpenseCategory::factory()->create();
        $other = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);
        $component = Livewire::test('pages::expense-categories.form', ['categoryId' => $category->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('categoryId', $other->id);
    }

    public function test_save_rechecks_ownership_after_mount(): void
    {
        $category = ExpenseCategory::factory()->create(['name' => 'Original']);
        $other = User::factory()->create();
        $this->actingAs($category->user);
        $component = Livewire::test('pages::expense-categories.form', ['categoryId' => $category->id]);
        $category->user()->associate($other)->save();

        $component->set('name', 'Changed')->call('save')->assertNotFound();

        $this->assertSame('Original', $category->refresh()->name);
    }

    public function test_livewire_rejects_injected_user_id_state(): void
    {
        $category = ExpenseCategory::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($category->user);
        $component = Livewire::test('pages::expense-categories.form', ['categoryId' => $category->id]);

        try {
            $component->set('user_id', $other->id);
            $this->fail('An undeclared owner property must be rejected.');
        } catch (PublicPropertyNotFoundException) {
            $this->assertNotSame($other->id, $category->refresh()->user_id);
        }
    }

    public function test_user_can_rename_category(): void
    {
        $category = ExpenseCategory::factory()->create(['name' => 'Old name']);
        $this->actingAs($category->user);

        Livewire::test('pages::expense-categories.form', ['categoryId' => $category->id])
            ->set('name', '  New name  ')->call('save')->assertHasNoErrors();

        $this->assertSame('New name', $category->refresh()->name);
    }

    public function test_database_seeding_provisions_defaults(): void
    {
        $this->seed();

        $user = User::where('email', 'test@example.com')->sole();
        $this->assertSame(8, $user->expenseCategories()->count());
    }

    public function test_submitted_owner_is_ignored_and_mass_assignment_cannot_change_ownership(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::expense-categories.form')->set('name', 'Pets')
            ->call('save', ['user_id' => $other->id])->assertHasNoErrors();
        $category = $user->expenseCategories()->sole();
        $category->fill(['user_id' => $other->id])->save();

        $this->assertSame($user->id, $category->refresh()->user_id);
        $this->assertSame(0, $other->expenseCategories()->count());
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_policy_grants_only_owner_access_and_never_deletion(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $own = ExpenseCategory::factory()->for($user)->create();
        $foreign = ExpenseCategory::factory()->create();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', ExpenseCategory::class));
        $this->assertTrue($gate->allows('create', ExpenseCategory::class));
        foreach (['view', 'update'] as $ability) {
            $this->assertTrue($gate->allows($ability, $own));
            $this->assertSame(404, $gate->inspect($ability, $foreign)->status());
        }
        $this->assertFalse($gate->allows('delete', $own));
        $this->assertFalse($gate->allows('delete', $foreign));
    }

    public function test_no_permanent_delete_route_or_action_is_exposed(): void
    {
        $category = ExpenseCategory::factory()->create();
        $this->actingAs($category->user);

        $this->get(route('expense-categories.index'))->assertDontSeeText('Delete');
        $this->delete('/expense-categories/'.$category->id)->assertNotFound();
        $component = Livewire::test('pages::expense-categories.index');
        $this->assertFalse(method_exists($component->instance(), 'delete'));
        $form = Livewire::test('pages::expense-categories.form', ['categoryId' => $category->id]);
        $this->assertFalse(method_exists($form->instance(), 'delete'));
        $this->assertModelExists($category);
    }

    public function test_names_are_escaped_and_unsafe_stored_visuals_use_defaults(): void
    {
        $category = ExpenseCategory::factory()->create([
            'name' => '<script>alert(1)</script>', 'icon' => '../bad-icon', 'color' => 'invalid',
        ]);
        $this->actingAs($category->user);

        $this->get(route('expense-categories.index'))->assertOk()
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('color: #64748B', false)->assertDontSee('../bad-icon');
    }
}
