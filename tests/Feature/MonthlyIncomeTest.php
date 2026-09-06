<?php

namespace Tests\Feature;

use App\Models\MonthlyIncome;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MonthlyIncomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_income(): void
    {
        $this->get(route('monthly-income.index'))->assertRedirect(route('login'));
        Livewire::test('pages::monthly-income.index')->assertForbidden();
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_page_and_mutations_are_private_to_the_authenticated_owner(string $role): void
    {
        $this->travelTo(now()->setDate(2026, 9, 6));
        $user = User::factory()->create(['role' => $role, 'currency' => 'USD']);
        $other = User::factory()->create(['default_monthly_income' => '9876.54']);
        $foreign = MonthlyIncome::factory()->for($other)->create(['year' => 2026, 'month' => 9, 'amount' => '9876.54']);
        $own = MonthlyIncome::factory()->for($user)->create(['year' => 2026, 'month' => 9, 'amount' => '1234.56']);
        $this->actingAs($user);

        $this->get(route('monthly-income.index'))->assertOk()->assertSeeText('USD 1234.56')->assertDontSee('9876.54');
        $this->get(route('dashboard'))->assertSee('href="'.route('monthly-income.index').'"', false);
        Livewire::test('pages::monthly-income.index')
            ->set('amount', '2500.50')->call('saveMonth', ['user_id' => $other->id, 'id' => $foreign->id])
            ->assertHasNoErrors()->set('default_monthly_income', '3000.00')
            ->call('saveDefault', ['user_id' => $other->id, 'role' => 'admin', 'is_active' => false, 'currency' => 'EUR'])->assertHasNoErrors();

        $this->assertSame('2500.50', $own->refresh()->amount);
        $this->assertSame('9876.54', $foreign->refresh()->amount);
        $this->assertSame('9876.54', $other->refresh()->default_monthly_income);
        $this->assertSame('3000.00', $user->refresh()->default_monthly_income);
        $this->assertSame($role, $user->role->value);
        $this->assertTrue($user->is_active);
        $this->assertSame('USD', $user->currency);
        $this->assertTrue($own->user->is($user));
        foreach (['view', 'update'] as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $own));
            $this->assertSame(404, Gate::forUser($user)->inspect($ability, $foreign)->status());
        }
        $this->assertFalse(Gate::forUser($user)->allows('delete', $own));
    }

    public function test_snapshots_are_lazy_idempotent_and_preserve_history_when_default_changes(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 10));
        $user = User::factory()->create(['default_monthly_income' => '5000.00']);
        $this->assertDatabaseCount('monthly_incomes', 0);
        $this->actingAs($user);

        $page = Livewire::test('pages::monthly-income.index')->assertSet('amount', '5000.00');
        $january = $user->monthlyIncomes()->sole();
        $original = $january->getRawOriginal();
        $page->call('openMonth')->call('openMonth')->assertSet('amount', '5000.00');
        $this->assertDatabaseCount('monthly_incomes', 1);
        $page->set('default_monthly_income', '7000.00')->call('saveDefault')->assertHasNoErrors();
        $page->call('openMonth')->assertSet('amount', '5000.00');
        $this->assertSame($original, $january->refresh()->getRawOriginal());
        $page->set('month', '2026-02')->call('openMonth')->assertSet('amount', '7000.00');

        $this->assertSame('7000.00', $user->monthlyIncomes()->where('month', 2)->sole()->amount);
        $this->assertDatabaseCount('monthly_incomes', 2);
    }

    public function test_null_default_creates_nothing_and_zero_can_be_saved_manually(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::monthly-income.index')->assertSee('No income configured for this month yet.');
        $this->assertDatabaseCount('monthly_incomes', 0);
        $page->set('amount', '0')->call('saveMonth')->assertHasNoErrors()->assertSet('amount', '0.00');
        $this->assertSame('0.00', auth()->user()->monthlyIncomes()->sole()->amount);
        $page->set('amount', '1200.25')->call('saveMonth')->assertHasNoErrors();
        $this->assertSame('1200.25', auth()->user()->monthlyIncomes()->sole()->amount);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    public function test_default_can_be_cleared_without_creating_or_changing_snapshots(?string $value): void
    {
        $user = User::factory()->create(['default_monthly_income' => '100.00']);
        $this->actingAs($user);
        Livewire::test('pages::monthly-income.index')->set('default_monthly_income', $value)->call('saveDefault')
            ->assertHasNoErrors()->set('month', '2020-01')->call('openMonth')->assertSet('amount', '');
        $this->assertNull($user->refresh()->default_monthly_income);
        $this->assertSame('100.00', $user->monthlyIncomes()->sole()->amount);
    }

    public function test_zero_default_creates_a_snapshot(): void
    {
        $user = User::factory()->create(['default_monthly_income' => '0.00']);
        $this->actingAs($user);
        $this->get(route('monthly-income.index'))->assertOk();
        $this->assertSame('0.00', $user->monthlyIncomes()->sole()->amount);
    }

    #[TestWith(['0', '0.00'])]
    #[TestWith(['0.00', '0.00'])]
    #[TestWith(['1000', '1000.00'])]
    #[TestWith(['1000.50', '1000.50'])]
    public function test_valid_money_preserves_exact_precision(string $input, string $expected): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test('pages::monthly-income.index')->set('default_monthly_income', $input)->call('saveDefault')->assertHasNoErrors()
            ->set('amount', $input)->call('saveMonth')->assertHasNoErrors();
        $this->assertSame($expected, $user->refresh()->default_monthly_income);
        $this->assertSame($expected, $user->monthlyIncomes()->sole()->amount);
    }

    public function test_maximum_amount_is_valid_and_sent_to_database_as_an_exact_string(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $bindings = [];
        DB::listen(function ($query) use (&$bindings): void {
            if (str_starts_with($query->sql, 'insert') || str_starts_with($query->sql, 'update')) {
                $bindings = [...$bindings, ...$query->bindings];
            }
        });

        Livewire::test('pages::monthly-income.index')->set('default_monthly_income', '9999999999999.99')
            ->call('saveDefault')->assertHasNoErrors()->assertSet('default_monthly_income', '9999999999999.99')
            ->set('amount', '9999999999999.99')->call('saveMonth')->assertHasNoErrors()->assertSet('amount', '9999999999999.99');

        $this->assertSame(2, count(array_filter($bindings, fn (mixed $value): bool => $value === '9999999999999.99')));
        $this->assertSame('9999999999999.99', MonthlyIncome::factory()->make(['amount' => '9999999999999.99'])->amount);
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertSame('9999999999999.99', $user->refresh()->default_monthly_income);
            $this->assertSame('9999999999999.99', $user->monthlyIncomes()->sole()->amount);
        }
    }

    public function test_competing_snapshot_insert_is_reused_without_overwriting_its_amount(): void
    {
        $user = User::factory()->create(['default_monthly_income' => '5000.00']);
        $this->actingAs($user);
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups, $user): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'monthly_incomes') && ++$lookups === 2) {
                DB::table('monthly_incomes')->insert([
                    'user_id' => $user->id, 'year' => now()->year, 'month' => now()->month,
                    'amount' => '4500.00', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        Livewire::test('pages::monthly-income.index')->assertSet('amount', '4500.00');
        $this->assertSame('4500.00', $user->monthlyIncomes()->sole()->amount);
    }

    #[TestWith(['-1'])]
    #[TestWith(['1e3'])]
    #[TestWith(['0.001'])]
    #[TestWith(['10000000000000.00'])]
    #[TestWith(['NaN'])]
    #[TestWith(['Infinity'])]
    public function test_invalid_money_is_rejected_without_writes(string $value): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test('pages::monthly-income.index')->set('default_monthly_income', $value)->call('saveDefault')
            ->assertHasErrors(['default_monthly_income' => 'regex'])->set('amount', $value)->call('saveMonth')
            ->assertHasErrors(['amount' => 'regex']);
        $this->assertNull($user->refresh()->default_monthly_income);
        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    public function test_monthly_amount_is_required(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::monthly-income.index')->call('saveMonth')->assertHasErrors(['amount' => 'required']);
        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    #[TestWith(['2026-00'])]
    #[TestWith(['2026-13'])]
    #[TestWith(['invalid'])]
    #[TestWith(['0999-12'])]
    #[TestWith(['10000-01'])]
    public function test_invalid_month_selection_does_not_create_a_snapshot(string $month): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::monthly-income.index')->set('month', $month)->call('openMonth')->assertHasErrors('month');
        $this->assertDatabaseCount('monthly_incomes', 0);
    }

    public function test_history_only_lists_existing_months_and_pagination_does_not_create_snapshots(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 6));
        $user = User::factory()->create();
        MonthlyIncome::factory()->for($user)->count(13)->sequence(...array_map(fn (int $year): array => ['year' => $year, 'month' => 1], range(2000, 2012)))->create();
        $this->actingAs($user);
        $page = Livewire::test('pages::monthly-income.index')->assertSeeInOrder(['January 2012', 'January 2011']);
        $page->set('default_monthly_income', '50.00')->call('saveDefault')->call('gotoPage', 2)->assertSee('January 2000');
        $this->assertSame(13, $user->monthlyIncomes()->count());
        $this->assertFalse($user->monthlyIncomes()->where('year', 2026)->exists());
    }

    public function test_duplicate_period_is_rejected_by_database(): void
    {
        $record = MonthlyIncome::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        MonthlyIncome::factory()->for($record->user)->create(['year' => $record->year, 'month' => $record->month]);
    }

    public function test_submitted_owner_cannot_redirect_creation_or_mass_assignment(): void
    {
        $other = MonthlyIncome::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test('pages::monthly-income.index')->set('month', sprintf('%04d-%02d', $other->year, $other->month))->call('openMonth')
            ->assertSet('amount', '')->set('amount', '10.00')->call('saveMonth', ['user_id' => $other->user_id])->assertHasNoErrors();
        $own = $user->monthlyIncomes()->sole();
        $own->fill(['user_id' => $other->user_id])->save();
        $this->assertSame($user->id, $own->refresh()->user_id);
        $this->assertDatabaseCount('monthly_incomes', 2);
    }

    #[TestWith(['user_id'])]
    #[TestWith(['userId'])]
    #[TestWith(['role'])]
    #[TestWith(['is_active'])]
    #[TestWith(['currency'])]
    public function test_undeclared_sensitive_state_is_rejected(string $property): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::monthly-income.index');
        $this->expectException(PublicPropertyNotFoundException::class);
        $page->set($property, '1');
    }

    public function test_selected_month_cannot_be_tampered_with(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::monthly-income.index');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('selectedMonth', '2026-13');
    }

    public function test_unopened_selector_does_not_redirect_monthly_save(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 6));
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::monthly-income.index')->set('month', '2027-01')->set('amount', '10.00')->call('saveMonth')->assertHasNoErrors();
        $this->assertDatabaseHas('monthly_incomes', ['year' => 2026, 'month' => 9, 'amount' => '10.00']);
        $this->assertDatabaseCount('monthly_incomes', 1);
    }

    public function test_no_permanent_delete_is_exposed(): void
    {
        $record = MonthlyIncome::factory()->create();
        $this->actingAs($record->user);
        $this->get(route('monthly-income.index'))->assertDontSeeText('Delete');
        $this->delete('/monthly-income/'.$record->id)->assertNotFound();
        $page = Livewire::test('pages::monthly-income.index');
        $this->assertFalse(method_exists($page->instance(), 'delete'));
        $this->assertModelExists($record);
    }
}
