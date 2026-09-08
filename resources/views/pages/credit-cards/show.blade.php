<?php

use App\Actions\CalculateCreditCardBill;
use App\Actions\ProjectCreditCardBill;
use App\BillingCycleResolver;
use App\CreditCardBill;
use App\Models\CreditCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Credit card bill')] class extends Component {
    #[Locked]
    public int $creditCardId;

    public string $billMonth = '';

    #[Locked]
    public string $selectedBillMonth = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', CreditCard::class);
    }

    public function mount(int $creditCardId): void
    {
        $this->creditCardId = $creditCardId;
        $creditCard = $this->ownedCreditCard();
        Gate::authorize('view', $creditCard);
        $currentCycle = app(BillingCycleResolver::class)->current(
            $creditCard->cycle_start_day,
            $creditCard->due_day,
            CarbonImmutable::today(),
        );
        $month = request()->query('month');
        $valid = Validator::make(['month' => $month], [
            'month' => ['required', 'string', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12'],
        ])->passes();
        $this->billMonth = $valid ? $month : $currentCycle->dueDate->format('Y-m');
        $this->openBill();
    }

    public function openBill(): void
    {
        $this->validate([
            'billMonth' => ['required', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12'],
        ]);
        $creditCard = $this->ownedCreditCard();
        Gate::authorize('view', $creditCard);
        $resolver = app(BillingCycleResolver::class);
        $cycle = $resolver->dueIn(
            CarbonImmutable::createFromFormat('!Y-m', $this->billMonth),
            $creditCard->cycle_start_day,
            $creditCard->due_day,
        );
        $currentCycle = $resolver->current($creditCard->cycle_start_day, $creditCard->due_day, CarbonImmutable::today());

        if ($cycle->dueDate->format('Y-m') === $currentCycle->dueDate->format('Y-m')) {
            app(ProjectCreditCardBill::class)->handle(auth()->user(), $creditCard, $cycle);
        }

        $this->selectedBillMonth = $this->billMonth;
        $this->resetValidation();
        unset($this->bill, $this->creditCard, $this->isCurrentBill);
    }

    #[Computed]
    public function creditCard(): CreditCard
    {
        return $this->ownedCreditCard();
    }

    #[Computed]
    public function bill(): CreditCardBill
    {
        $cycle = app(BillingCycleResolver::class)->dueIn(
            CarbonImmutable::createFromFormat('!Y-m', $this->selectedBillMonth),
            $this->creditCard->cycle_start_day,
            $this->creditCard->due_day,
        );

        return app(CalculateCreditCardBill::class)->handle(auth()->user(), $this->creditCard, $cycle);
    }

    #[Computed]
    public function isCurrentBill(): bool
    {
        $currentCycle = app(BillingCycleResolver::class)->current(
            $this->creditCard->cycle_start_day,
            $this->creditCard->due_day,
            CarbonImmutable::today(),
        );

        return $this->bill->cycle->dueDate->format('Y-m') === $currentCycle->dueDate->format('Y-m');
    }

    private function ownedCreditCard(): CreditCard
    {
        $creditCard = auth()->user()->creditCards()->find($this->creditCardId);
        abort_if($creditCard === null, 404);

        return $creditCard;
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">{{ $this->creditCard->name }}</flux:heading>
            <flux:badge :color="$this->creditCard->is_active ? 'green' : 'zinc'">{{ $this->creditCard->is_active ? 'Active' : 'Inactive' }}</flux:badge>
        </div>
        <flux:button :href="route('credit-cards.index')" wire:navigate>Back to credit cards</flux:button>
    </div>
    <form wire:submit="openBill" class="flex flex-wrap items-end gap-3">
        <flux:input wire:model="billMonth" label="Bill due in" type="month" min="1000-01" max="9999-12" required />
        <flux:button type="submit" wire:loading.attr="disabled">Open bill</flux:button>
    </form>
    <div class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <flux:heading size="lg">{{ $this->isCurrentBill ? 'Current / Projected Bill' : 'Calculated Bill' }}</flux:heading>
        <dl class="grid gap-2 sm:grid-cols-[auto_1fr] sm:gap-x-6">
            <dt>Cycle</dt><dd>{{ $this->bill->cycle->start->toDateString() }} – {{ $this->bill->cycle->end->toDateString() }}</dd>
            <dt>Due</dt><dd>{{ $this->bill->cycle->dueDate->toDateString() }}</dd>
            <dt>Total</dt><dd class="break-words text-2xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->bill->total }}</dd>
        </dl>
    </div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Expenses in the bill due {{ $this->bill->cycle->dueDate->toDateString() }}</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                <th scope="col" class="px-4 py-3">Date</th>
                <th scope="col" class="px-4 py-3">Expense</th>
                <th scope="col" class="px-4 py-3">Category</th>
                <th scope="col" class="px-4 py-3">Amount</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->bill->expenses as $expense)
                    <tr wire:key="bill-expense-{{ $expense->id }}">
                        <td class="whitespace-nowrap px-4 py-3">{{ $expense->expense_date->toDateString() }}</td>
                        <td class="px-4 py-3">{{ $expense->name }}</td>
                        <td class="px-4 py-3">
                            @if ($category = $expense->expenseCategory)
                                <span class="inline-flex items-center gap-2">
                                    <span class="shrink-0" style="color: {{ $category->safeColor() }}" aria-hidden="true"><flux:icon :name="$category->safeIcon()" class="size-5" /></span>
                                    <span>{{ $category->name }}</span>
                                </span>
                            @else
                                Category unavailable
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ auth()->user()->currency }} {{ $expense->amount }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6"><flux:text>No expenses in this billing cycle.</flux:text></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
