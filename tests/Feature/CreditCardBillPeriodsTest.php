<?php

namespace Tests\Feature;

use App\BillingCycleResolver;
use App\CreditCardBillPeriodResolver;
use App\Models\CreditCard;
use App\Models\CreditCardBillPeriod;
use App\Models\Expense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CreditCardBillPeriodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bill_without_override_falls_back_to_calculated_period_and_preserves_due_date(): void
    {
        $card = CreditCard::factory()->create(['cycle_start_day' => 10, 'due_day' => 20]);
        $resolved = app(CreditCardBillPeriodResolver::class)->resolve($card, CarbonImmutable::parse('2026-09-01'));

        $this->assertFalse($resolved->isCustom());
        $this->assertSame('2026-08-10', $resolved->cycle->start->toDateString());
        $this->assertSame('2026-09-09', $resolved->cycle->end->toDateString());
        $this->assertSame('2026-09-20', $resolved->cycle->dueDate->toDateString());

        $this->actingAs($card->user);
        Livewire::withQueryParams(['month' => '2026-09'])
            ->test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->assertSet('periodStart', '2026-08-10')
            ->assertSet('periodEnd', '2026-09-09')
            ->assertSeeText('Calculated period')
            ->assertSeeText('2026-08-10 – 2026-09-09')
            ->assertSeeText('2026-09-20');
    }

    public function test_custom_period_drives_the_exact_visible_expenses_and_total_with_inclusive_boundaries(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $card = CreditCard::factory()->create(['name' => 'Itaú Multi Black', 'cycle_start_day' => 10, 'due_day' => 20]);
        $expenses = collect([
            '2026-08-10' => '1.00',
            '2026-08-11' => '2.00',
            '2026-09-09' => '3.00',
            '2026-09-10' => '4.00',
            '2026-09-11' => '5.00',
            '2026-09-12' => '6.00',
            '2026-09-13' => '7.00',
        ])->map(fn (string $amount, string $date): Expense => Expense::factory()->for($card->user)->create([
            'name' => 'Boundary '.$date,
            'credit_card_id' => $card->id,
            'expense_date' => $date,
            'amount' => $amount,
        ]));
        $this->actingAs($card->user);

        $page = Livewire::withQueryParams(['month' => '2026-09'])
            ->test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->set('periodStart', '2026-08-11')
            ->set('periodEnd', '2026-09-12')
            ->call('savePeriod')
            ->assertHasNoErrors()
            ->assertSeeText('Custom period')
            ->assertSeeText('2026-08-11 – 2026-09-12')
            ->assertSeeText('2026-09-20')
            ->assertSeeText('Total so far')
            ->assertSeeText('R$ 20.00')
            ->assertSeeText([
                'Boundary 2026-08-11', 'Boundary 2026-09-09', 'Boundary 2026-09-10',
                'Boundary 2026-09-11', 'Boundary 2026-09-12',
            ])
            ->assertDontSeeText(['Boundary 2026-08-10', 'Boundary 2026-09-13']);

        $this->assertDatabaseHas('credit_card_bill_periods', [
            'credit_card_id' => $card->id,
            'due_year' => 2026,
            'due_month' => 9,
        ]);
        $savedPeriod = $card->billPeriods()->sole();
        $this->assertSame('2026-08-11', $savedPeriod->start_date->toDateString());
        $this->assertSame('2026-09-12', $savedPeriod->end_date->toDateString());
        $this->assertSame($expenses->slice(1, 5)->pluck('id')->values()->all(), $page->get('bill')->expenses->pluck('id')->all());
        $this->assertSame('20.00', $page->get('bill')->total);
        $this->assertSame('20.00', $page->get('bill')->totalSoFar);
    }

    public function test_override_is_due_month_specific_and_can_be_updated_then_reset(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $card = CreditCard::factory()->create(['cycle_start_day' => 10, 'due_day' => 20]);
        Expense::factory()->for($card->user)->create([
            'credit_card_id' => $card->id, 'expense_date' => '2026-09-10', 'amount' => '10.00',
        ]);
        $this->actingAs($card->user);

        $page = Livewire::withQueryParams(['month' => '2026-09'])
            ->test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->set('periodStart', '2026-08-11')
            ->set('periodEnd', '2026-09-12')
            ->call('savePeriod')
            ->set('periodStart', '2026-08-12')
            ->set('periodEnd', '2026-09-11')
            ->call('savePeriod')
            ->assertHasNoErrors()
            ->assertSeeText('2026-08-12 – 2026-09-11')
            ->assertSeeText('R$ 10.00');

        $this->assertSame(1, $card->billPeriods()->count());
        $this->assertSame('2026-08-12', $card->billPeriods()->sole()->start_date->toDateString());

        $page->set('billMonth', '2026-10')
            ->call('openBill')
            ->assertSet('selectedBillMonth', '2026-10')
            ->assertSet('periodStart', '2026-09-10')
            ->assertSet('periodEnd', '2026-10-09')
            ->assertSeeText('Calculated period')
            ->assertSeeText('R$ 10.00');

        $page->set('billMonth', '2026-09')
            ->call('openBill')
            ->assertSeeText('Custom period')
            ->call('resetPeriod')
            ->assertHasNoErrors()
            ->assertSet('periodStart', '2026-08-10')
            ->assertSet('periodEnd', '2026-09-09')
            ->assertSeeText('Calculated period')
            ->assertSeeText('R$ 0.00');

        $this->assertSame(0, $card->billPeriods()->count());
    }

    #[TestWith(['', '2026-09-12', 'periodStart', 'required'])]
    #[TestWith(['2026-08-11', '', 'periodEnd', 'required'])]
    #[TestWith(['2026-02-30', '2026-09-12', 'periodStart', 'date_format'])]
    #[TestWith(['2026-09-13', '2026-09-12', 'periodStart', 'before_or_equal'])]
    public function test_period_dates_are_required_real_dates_in_chronological_order(string $start, string $end, string $field, string $rule): void
    {
        $card = CreditCard::factory()->create(['cycle_start_day' => 10, 'due_day' => 20]);
        $this->actingAs($card->user);

        Livewire::withQueryParams(['month' => '2026-09'])
            ->test('pages::credit-cards.show', ['creditCardId' => $card->id])
            ->set('periodStart', $start)
            ->set('periodEnd', $end)
            ->call('savePeriod')
            ->assertHasErrors([$field => $rule]);

        $this->assertDatabaseCount('credit_card_bill_periods', 0);
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_other_users_cannot_view_or_mutate_a_card_bill_period(string $role): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $foreignCard = CreditCard::factory()->create();
        CreditCardBillPeriod::factory()->for($foreignCard)->create();
        $this->actingAs($actor);

        $this->get(route('credit-cards.show', ['creditCardId' => $foreignCard->id, 'month' => '2026-09']))->assertNotFound();
        Livewire::withQueryParams(['month' => '2026-09'])
            ->test('pages::credit-cards.show', ['creditCardId' => $foreignCard->id])
            ->assertNotFound();

        $this->assertDatabaseHas('credit_card_bill_periods', [
            'credit_card_id' => $foreignCard->id,
        ]);
        $period = $foreignCard->billPeriods()->sole();
        $this->assertSame('2026-08-11', $period->start_date->toDateString());
        $this->assertSame('2026-09-12', $period->end_date->toDateString());
    }

    public function test_card_and_user_deletion_cascade_bill_period_overrides(): void
    {
        $directlyDeletedCard = CreditCard::factory()->create();
        CreditCardBillPeriod::factory()->for($directlyDeletedCard)->create();
        $directlyDeletedCard->delete();
        $this->assertDatabaseMissing('credit_card_bill_periods', ['credit_card_id' => $directlyDeletedCard->id]);

        $card = CreditCard::factory()->create();
        CreditCardBillPeriod::factory()->for($card)->create();
        $this->actingAs($card->user);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertDatabaseMissing('credit_card_bill_periods', ['credit_card_id' => $card->id]);
        $this->assertDatabaseMissing('credit_cards', ['id' => $card->id]);
    }

    public function test_global_cycle_resolver_remains_independent_of_bill_period_override(): void
    {
        $card = CreditCard::factory()->create(['cycle_start_day' => 10, 'due_day' => 20]);
        CreditCardBillPeriod::factory()->for($card)->create();

        $cycle = app(BillingCycleResolver::class)->dueInFor($card, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame('2026-08-10', $cycle->start->toDateString());
        $this->assertSame('2026-09-09', $cycle->end->toDateString());
        $this->assertSame('2026-09-20', $cycle->dueDate->toDateString());
    }
}
