<?php

namespace Tests\Feature;

use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CreditCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_credit_card_pages(): void
    {
        $creditCard = CreditCard::factory()->create();

        foreach (['credit-cards.index', 'credit-cards.create', 'credit-cards.show', 'credit-cards.edit'] as $route) {
            $parameters = in_array($route, ['credit-cards.show', 'credit-cards.edit'], true) ? $creditCard->id : [];
            $this->get(route($route, $parameters))->assertRedirect(route('login'));
        }

        Livewire::test('pages::credit-cards.index')->assertForbidden();
        Livewire::test('pages::credit-cards.form')->assertForbidden();
        Livewire::test('pages::credit-cards.show', ['creditCardId' => $creditCard->id])->assertForbidden();
    }

    public function test_owner_can_create_and_edit_an_active_credit_card(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::credit-cards.form')
            ->set(['name' => '  Nubank Mastercard  ', 'cycle_start_day' => 13, 'due_day' => 20])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('credit-cards.index'));

        $creditCard = $user->creditCards()->sole();
        $this->assertSame('Nubank Mastercard', $creditCard->name);
        $this->assertSame(13, $creditCard->cycle_start_day);
        $this->assertSame(20, $creditCard->due_day);
        $this->assertTrue($creditCard->is_active);

        Livewire::test('pages::credit-cards.form', ['creditCardId' => $creditCard->id])
            ->set(['name' => 'Amex', 'cycle_start_day' => 31, 'due_day' => 5])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Amex', $creditCard->refresh()->name);
        $this->assertSame(31, $creditCard->cycle_start_day);
        $this->assertSame(5, $creditCard->due_day);
        $this->assertTrue($creditCard->is_active);
    }

    #[TestWith(['cycle_start_day', 0])]
    #[TestWith(['cycle_start_day', 32])]
    #[TestWith(['due_day', 0])]
    #[TestWith(['due_day', 32])]
    public function test_cycle_and_due_days_must_be_between_one_and_thirty_one(string $field, int $value): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::credit-cards.form')
            ->set(['name' => 'Nubank', 'cycle_start_day' => 13, 'due_day' => 20, $field => $value])
            ->call('save')
            ->assertHasErrors([$field => 'between']);

        $this->assertSame(0, $user->creditCards()->count());
    }

    public function test_name_is_unique_per_user_but_shared_across_users(): void
    {
        $creditCard = CreditCard::factory()->create(['name' => 'Nubank']);
        CreditCard::factory()->create(['name' => 'Nubank']);
        $this->actingAs($creditCard->user);

        Livewire::test('pages::credit-cards.form')
            ->set(['name' => 'Nubank', 'cycle_start_day' => 1, 'due_day' => 10])
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);

        $this->assertSame(1, $creditCard->user->creditCards()->count());
        $this->assertDatabaseCount('credit_cards', 2);
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_credit_cards_are_owner_scoped_without_admin_bypass(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $own = CreditCard::factory()->for($user)->create(['name' => 'Own card']);
        $foreign = CreditCard::factory()->create(['name' => 'Foreign secret']);
        $this->actingAs($user);

        $this->get(route('credit-cards.index'))->assertSeeText('Own card')->assertDontSeeText('Foreign secret');
        $this->get(route('credit-cards.show', $own->id))->assertSeeText('Own card');
        $this->get(route('credit-cards.show', $foreign->id))->assertNotFound();
        $this->get(route('credit-cards.edit', $foreign->id))->assertNotFound();
        Livewire::test('pages::credit-cards.index')->call('setActive', $foreign->id, false)->assertNotFound();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', CreditCard::class));
        $this->assertTrue(Gate::forUser($user)->allows('create', CreditCard::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', $own));
        $this->assertSame(404, Gate::forUser($user)->inspect('view', $foreign)->status());
        $this->assertSame(404, Gate::forUser($user)->inspect('update', $foreign)->status());
        $this->assertFalse(Gate::forUser($user)->allows('delete', $own));
    }

    public function test_activation_preserves_configuration_and_no_delete_action_is_exposed(): void
    {
        $creditCard = CreditCard::factory()->create();
        $this->actingAs($creditCard->user);

        Livewire::test('pages::credit-cards.index')->call('setActive', $creditCard->id, false)->assertHasNoErrors();

        $this->assertFalse($creditCard->refresh()->is_active);
        $this->assertSame(13, $creditCard->cycle_start_day);
        $this->assertSame(20, $creditCard->due_day);
        $this->get(route('credit-cards.index'))->assertDontSeeText('Delete');
        $this->delete('/credit-cards/'.$creditCard->id)->assertMethodNotAllowed();
        $this->assertFalse(method_exists(Livewire::test('pages::credit-cards.index')->instance(), 'delete'));
    }

    public function test_database_unique_constraint_remains_an_integrity_boundary(): void
    {
        $creditCard = CreditCard::factory()->create(['name' => 'Nubank']);

        $this->expectException(UniqueConstraintViolationException::class);
        CreditCard::factory()->for($creditCard->user)->create(['name' => 'Nubank']);
    }

    public function test_credit_card_id_is_locked_against_browser_tampering(): void
    {
        $creditCard = CreditCard::factory()->create();
        $foreign = CreditCard::factory()->create();
        $this->actingAs($creditCard->user);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test('pages::credit-cards.form', ['creditCardId' => $creditCard->id])
            ->set('creditCardId', $foreign->id);
    }

    public function test_sidebar_places_credit_cards_next_to_accounts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertSeeInOrder([route('accounts.index'), 'Accounts', route('credit-cards.index'), 'Credit Cards'], false);
    }

    public function test_whole_user_deletion_cascades_credit_cards(): void
    {
        $creditCard = CreditCard::factory()->create();
        $this->actingAs($creditCard->user);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertDatabaseMissing('credit_cards', ['id' => $creditCard->id]);
        $this->assertDatabaseMissing('users', ['id' => $creditCard->user_id]);
    }
}
