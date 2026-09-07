<?php

use App\Actions\CalculateCreditCardBill;
use App\Actions\MaterializeFixedExpensesForMonth;
use App\BillingCycleResolver;
use App\CreditCardBill;
use App\Models\CreditCard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Credit Cards')] class extends Component {
    public function boot(): void
    {
        Gate::authorize('viewAny', CreditCard::class);
    }

    public function mount(): void
    {
        $resolver = app(BillingCycleResolver::class);
        $months = [];

        foreach (auth()->user()->creditCards()->get() as $creditCard) {
            $cycle = $resolver->current($creditCard->cycle_start_day, $creditCard->due_day, CarbonImmutable::today());
            foreach ($cycle->intersectingMonths() as $month) {
                $months[$month->format('Y-m')] = $month;
            }
        }

        foreach ($months as $month) {
            app(MaterializeFixedExpensesForMonth::class)->handle(auth()->user(), $month->year, $month->month);
        }
    }

    /** @return Collection<int, CreditCard> */
    #[Computed]
    public function creditCards(): Collection
    {
        return auth()->user()->creditCards()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, CreditCardBill> */
    #[Computed]
    public function currentBills(): array
    {
        $resolver = app(BillingCycleResolver::class);
        $calculator = app(CalculateCreditCardBill::class);
        $today = CarbonImmutable::today();
        $bills = [];

        foreach ($this->creditCards as $creditCard) {
            $cycle = $resolver->current($creditCard->cycle_start_day, $creditCard->due_day, $today);
            $bills[$creditCard->id] = $calculator->handle(auth()->user(), $creditCard, $cycle);
        }

        return $bills;
    }

    public function setActive(int $creditCardId, bool $active): void
    {
        $creditCard = auth()->user()->creditCards()->find($creditCardId);
        abort_if($creditCard === null, 404);
        Gate::authorize('update', $creditCard);
        $creditCard->is_active = $active;
        $creditCard->save();
        unset($this->creditCards, $this->currentBills);
        session()->flash('status', $active ? 'Credit card activated.' : 'Credit card deactivated.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Credit Cards</flux:heading>
        <flux:button variant="primary" :href="route('credit-cards.create')" wire:navigate>Add credit card</flux:button>
    </div>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your credit cards</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                <th scope="col" class="px-4 py-3">Name</th>
                <th scope="col" class="px-4 py-3">Cycle Starts</th>
                <th scope="col" class="px-4 py-3">Due Day</th>
                <th scope="col" class="px-4 py-3">Current / Projected Bill</th>
                <th scope="col" class="px-4 py-3">Next Due</th>
                <th scope="col" class="px-4 py-3">Status</th>
                <th scope="col" class="px-4 py-3">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->creditCards as $creditCard)
                    @php($bill = $this->currentBills[$creditCard->id])
                    <tr wire:key="credit-card-{{ $creditCard->id }}">
                        <td class="px-4 py-3">{{ $creditCard->name }}</td>
                        <td class="px-4 py-3">Day {{ $creditCard->cycle_start_day }}</td>
                        <td class="px-4 py-3">Day {{ $creditCard->due_day }}</td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ auth()->user()->currency }} {{ $bill->total }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $bill->cycle->dueDate->toDateString() }}</td>
                        <td class="px-4 py-3"><flux:badge :color="$creditCard->is_active ? 'green' : 'zinc'">{{ $creditCard->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                        <td class="px-4 py-3"><div class="flex flex-wrap gap-2">
                            <flux:button size="sm" :href="route('credit-cards.show', ['creditCardId' => $creditCard->id, 'month' => $bill->cycle->dueDate->format('Y-m')])" wire:navigate>View bill</flux:button>
                            <flux:button size="sm" :href="route('credit-cards.edit', $creditCard->id)" wire:navigate>Edit</flux:button>
                            <flux:button size="sm" wire:click="setActive({{ $creditCard->id }}, {{ $creditCard->is_active ? 'false' : 'true' }})" wire:confirm="Change this credit card's active status?" wire:loading.attr="disabled">{{ $creditCard->is_active ? 'Deactivate' : 'Activate' }}</flux:button>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6"><flux:text>No credit cards yet. Add one to classify card expenses and calculate bills.</flux:text></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
