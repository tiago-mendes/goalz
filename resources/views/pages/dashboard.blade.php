<?php

use App\Actions\MaterializeFixedExpensesForMonth;
use App\Actions\ProjectCreditCardBill;
use App\Actions\ResolveMonthlyIncome;
use App\BillingCycleResolver;
use App\GoalProgressColor;
use App\GoalStatus;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\Goal;
use App\Models\MonthlyIncome;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Monthly Overview')] class extends Component {
    public string $month = '';

    #[Locked]
    public string $selectedMonth = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', MonthlyIncome::class);
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
        $this->openMonth();
    }

    public function openMonth(): void
    {
        $this->validate(['month' => ['required', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12']]);
        $this->selectedMonth = $this->month;
        $period = CarbonImmutable::createFromFormat('!Y-m', $this->selectedMonth);
        app(ResolveMonthlyIncome::class)->handle(auth()->user(), $period->year, $period->month);
        app(MaterializeFixedExpensesForMonth::class)->handle(auth()->user(), $period->year, $period->month);
        $this->resetValidation();
        unset($this->income, $this->expenses, $this->actualTotal, $this->expenseBreakdown, $this->remaining);
        unset($this->accounts, $this->financialPosition, $this->goals, $this->creditCardBills);
    }

    #[Computed]
    public function income(): ?MonthlyIncome
    {
        return auth()->user()->monthlyIncomes()->where('year', (int) substr($this->selectedMonth, 0, 4))
            ->where('month', (int) substr($this->selectedMonth, 5, 2))->first();
    }

    #[Computed]
    public function expenses(): Collection
    {
        return $this->expenseMonthQuery()->whereNull('deleted_by_user_at')
            ->orderByDesc('expense_date')->orderByDesc('id')->get();
    }

    #[Computed]
    public function actualTotal(): string
    {
        return $this->expenseBreakdown()['total'];
    }

    /** @return array{total: string, recurring: string, recurringPercentage: string, manual: string, manualPercentage: string} */
    #[Computed]
    public function expenseBreakdown(): array
    {
        $recurring = BigDecimal::of('0.00');
        $manual = BigDecimal::of('0.00');
        foreach ($this->expenses as $expense) {
            if ($expense->fixed_expense_id === null) {
                $manual = $manual->plus($expense->amount);
                continue;
            }

            $recurring = $recurring->plus($expense->amount);
        }

        $total = $recurring->plus($manual);

        return [
            'total' => (string) $total->toScale(2),
            'recurring' => (string) $recurring->toScale(2),
            'recurringPercentage' => $this->percentage($recurring, $total),
            'manual' => (string) $manual->toScale(2),
            'manualPercentage' => $this->percentage($manual, $total),
        ];
    }

    #[Computed]
    public function remaining(): ?string
    {
        return $this->income === null ? null : (string) BigDecimal::of($this->income->amount)->minus($this->actualTotal)->toScale(2);
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return auth()->user()->accounts()
            ->with('goalAccountAllocations')
            ->where('is_active', true)
            ->get();
    }

    /** @return array{totalAssets: string, allocatedAssets: string, freeAssets: string, overallocatedCount: int, overallocatedTotal: string} */
    #[Computed]
    public function financialPosition(): array
    {
        $totalAssets = BigDecimal::of('0.00');
        $allocatedAssets = BigDecimal::of('0.00');
        $freeAssets = BigDecimal::of('0.00');
        $overallocatedCount = 0;
        $overallocatedTotal = BigDecimal::of('0.00');

        foreach ($this->accounts as $account) {
            $totalAssets = $totalAssets->plus($account->current_balance);
            $allocatedAssets = $allocatedAssets->plus($account->allocatedAmount());
            $freeAssets = $freeAssets->plus($account->availableAmount());

            if (BigDecimal::of($account->availableAmount())->isNegative()) {
                $overallocatedCount++;
                $overallocatedTotal = $overallocatedTotal->plus($account->overallocatedAmount());
            }
        }

        return [
            'totalAssets' => (string) $totalAssets->toScale(2),
            'allocatedAssets' => (string) $allocatedAssets->toScale(2),
            'freeAssets' => (string) $freeAssets->toScale(2),
            'overallocatedCount' => $overallocatedCount,
            'overallocatedTotal' => (string) $overallocatedTotal->toScale(2),
        ];
    }

    /** @return Collection<int, Goal> */
    #[Computed]
    public function goals(): Collection
    {
        return Goal::query()->accessibleTo(auth()->user())
            ->with(['goalAccountAllocations:id,goal_id,amount', 'owner:id,name'])
            ->whereIn('status', [GoalStatus::Active->value, GoalStatus::Paused->value])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{card: CreditCard, bill: \App\CreditCardBill}> */
    #[Computed]
    public function creditCardBills(): array
    {
        $dueMonth = CarbonImmutable::createFromFormat('!Y-m', $this->selectedMonth);
        $resolver = app(BillingCycleResolver::class);
        $projector = app(ProjectCreditCardBill::class);
        $bills = [];

        foreach (auth()->user()->creditCards()->orderBy('name')->orderBy('id')->get() as $creditCard) {
            $cycle = $resolver->dueIn($dueMonth, $creditCard->cycle_start_day, $creditCard->due_day);
            $bill = $projector->handle(auth()->user(), $creditCard, $cycle);

            if ($creditCard->is_active || $bill->expenses->isNotEmpty()) {
                $bills[] = ['card' => $creditCard, 'bill' => $bill];
            }
        }

        return $bills;
    }

    /** @return HasMany<Expense, \App\Models\User> */
    private function expenseMonthQuery(): HasMany
    {
        return auth()->user()->expenses()->forMonth(CarbonImmutable::createFromFormat('!Y-m', $this->selectedMonth))
            ->with(['expenseCategory' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id())]);
    }

    private function percentage(BigDecimal $part, BigDecimal $total): string
    {
        if ($total->isZero()) {
            return '0';
        }

        return (string) $part->multipliedBy(100)->dividedBy($total, 1, RoundingMode::HalfUp);
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">{{ CarbonImmutable::createFromFormat('!Y-m', $selectedMonth)->format('F Y') }}</flux:heading>
            <flux:text>Monthly overview</flux:text>
        </div>
        <form wire:submit="openMonth" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="month" label="Month" type="month" min="1000-01" max="9999-12" required />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Open month</flux:button>
        </form>
    </div>

    <section x-data="{ isOpen: true }" x-bind:class="{ 'bg-white dark:bg-zinc-900': isOpen, 'bg-zinc-50 dark:bg-zinc-800': !isOpen }" class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="month-info-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2" id="month-info-heading">Month Info</flux:heading>
                <flux:text>Summary and expenses for the selected month.</flux:text>
            </div>
            <button type="button" class="inline-flex items-center rounded-lg p-2 text-zinc-700 hover:bg-zinc-100 focus:outline-hidden focus:ring-2 focus:ring-accent dark:text-zinc-200 dark:hover:bg-zinc-800" x-bind:aria-expanded="isOpen" x-bind:aria-label="isOpen ? 'Collapse Month Info' : 'Expand Month Info'" aria-controls="month-info-content" @click="isOpen = !isOpen">
                <flux:icon.chevron-up x-show="isOpen" class="size-5" aria-hidden="true" />
                <flux:icon.chevron-down x-show="!isOpen" class="size-5" aria-hidden="true" />
                <span class="sr-only" x-text="isOpen ? 'Collapse Month Info' : 'Expand Month Info'"></span>
            </button>
        </div>
        <div id="month-info-content" x-show="isOpen" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" wire:loading.class="opacity-50" wire:target="openMonth">
        <div class="space-y-3 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading level="2">Monthly Income</flux:heading>
            <p class="break-words text-2xl font-semibold tabular-nums">{{ $this->income ? auth()->user()->currency.' '.$this->income->amount : 'Not configured' }}</p>
            <flux:link :href="route('monthly-income.index')" wire:navigate>Manage income</flux:link>
        </div>
        <div class="space-y-3 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading level="2">Expenses</flux:heading>
            <p class="break-words text-2xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->actualTotal }}</p>
            <div class="space-y-1 text-sm">
                <p>Recurring <span class="tabular-nums">{{ auth()->user()->currency }} {{ $this->expenseBreakdown['recurring'] }}</span> · <span class="tabular-nums">{{ $this->expenseBreakdown['recurringPercentage'] }}%</span></p>
                <p>Manual <span class="tabular-nums">{{ auth()->user()->currency }} {{ $this->expenseBreakdown['manual'] }}</span> · <span class="tabular-nums">{{ $this->expenseBreakdown['manualPercentage'] }}%</span></p>
            </div>
            <flux:link :href="route('expenses.index', ['month' => $selectedMonth])" wire:navigate>Manage expenses</flux:link>
        </div>
        <div class="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading level="2">Remaining</flux:heading>
            <p class="break-words text-2xl font-semibold tabular-nums">{{ $this->remaining !== null ? auth()->user()->currency.' '.$this->remaining : 'Not available' }}</p>
            <flux:text>Income minus actual expenses.</flux:text>
        </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading level="3">Expenses</flux:heading>
                <flux:button :href="route('expenses.index', ['month' => $selectedMonth])" wire:navigate>Manage expenses</flux:button>
            </div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700" wire:loading.class="opacity-50" wire:target="openMonth">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Expenses for {{ $selectedMonth }}</caption>
                    <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                        <th scope="col" class="px-4 py-3">Date</th><th scope="col" class="px-4 py-3">Name</th>
                        <th scope="col" class="px-4 py-3">Category</th><th scope="col" class="px-4 py-3">Source</th>
                        <th scope="col" class="px-4 py-3 text-right">Amount</th>
                    </tr></thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->expenses as $expense)
                            <tr wire:key="expense-{{ $selectedMonth }}-{{ $expense->id }}">
                                <td class="whitespace-nowrap px-4 py-3"><time datetime="{{ $expense->expense_date->toDateString() }}">{{ $expense->expense_date->format('M j') }}</time></td>
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
                                <td class="px-4 py-3"><flux:badge :color="$expense->fixed_expense_id === null ? 'zinc' : 'green'">{{ $expense->fixed_expense_id === null ? 'Manual' : 'Recurring' }}</flux:badge></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $expense->amount }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8"><flux:text>No expenses recorded for this month.</flux:text></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section x-data="{ isOpen: true }" x-bind:class="{ 'bg-white dark:bg-zinc-900': isOpen, 'bg-zinc-50 dark:bg-zinc-800': !isOpen }" class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="goals-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2" id="goals-heading">Goals</flux:heading>
                <flux:text>Current goal progress and designated funds.</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:link :href="route('goals.index')" wire:navigate>Manage goals</flux:link>
                <button type="button" class="inline-flex items-center rounded-lg p-2 text-zinc-700 hover:bg-zinc-100 focus:outline-hidden focus:ring-2 focus:ring-accent dark:text-zinc-200 dark:hover:bg-zinc-800" x-bind:aria-expanded="isOpen" x-bind:aria-label="isOpen ? 'Collapse Goals' : 'Expand Goals'" aria-controls="goals-content" @click="isOpen = !isOpen">
                    <flux:icon.chevron-up x-show="isOpen" class="size-5" aria-hidden="true" />
                    <flux:icon.chevron-down x-show="!isOpen" class="size-5" aria-hidden="true" />
                    <span class="sr-only" x-text="isOpen ? 'Collapse Goals' : 'Expand Goals'"></span>
                </button>
            </div>
        </div>
        <div id="goals-content" x-show="isOpen" class="space-y-4">
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <caption class="sr-only">Current goals</caption>
                    <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                        <th scope="col" class="px-4 py-3">Goal</th><th scope="col" class="px-4 py-3 text-right">Target</th>
                        <th scope="col" class="px-4 py-3 text-right">Allocated</th><th scope="col" class="px-4 py-3 text-right">Remaining</th>
                        <th scope="col" class="px-4 py-3">Progress</th><th scope="col" class="px-4 py-3">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->goals as $goal)
                            <tr wire:key="dashboard-goal-{{ $goal->id }}">
                                <td class="px-4 py-3 font-medium"><span class="inline-flex flex-wrap items-center gap-2">{{ $goal->name }} @if (! $goal->isOwnedBy(auth()->user()))<flux:badge color="blue">Shared</flux:badge><span class="text-xs font-normal text-zinc-600 dark:text-zinc-400">Owner: {{ $goal->owner->name }}</span>@endif</span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $goal->target_amount }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $goal->allocatedAmount() }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ BigDecimal::of($goal->remainingAmount())->isNegative() ? 'Overfunded · '.auth()->user()->currency.' '.$goal->overfundedAmount() : auth()->user()->currency.' '.$goal->remainingAmount() }}</td>
                                <td class="min-w-40 px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 min-w-20 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="{{ $goal->name }} funding progress" aria-valuenow="{{ $goal->visualProgressPercentage() }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full {{ GoalProgressColor::classes($goal->progressPercentage()) }}" style="width: {{ $goal->visualProgressPercentage() }}%"></div></div>
                                        <span class="tabular-nums">{{ $goal->progressPercentage() }}%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3"><flux:badge :color="$goal->status === GoalStatus::Active ? 'green' : 'yellow'">{{ $goal->status->label() }}</flux:badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6"><flux:text>No active or paused goals yet.</flux:text><div class="mt-3"><flux:button size="sm" :href="route('goals.create')" wire:navigate>Create goal</flux:button></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section x-data="{ isOpen: true }" x-bind:class="{ 'bg-white dark:bg-zinc-900': isOpen, 'bg-zinc-50 dark:bg-zinc-800': !isOpen }" class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="financial-position-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2" id="financial-position-heading">Current Financial Position</flux:heading>
                <flux:text>Current balances and allocations across your active accounts.</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:link :href="route('accounts.index')" wire:navigate>Manage accounts</flux:link>
                <button type="button" class="inline-flex items-center rounded-lg p-2 text-zinc-700 hover:bg-zinc-100 focus:outline-hidden focus:ring-2 focus:ring-accent dark:text-zinc-200 dark:hover:bg-zinc-800" x-bind:aria-expanded="isOpen" x-bind:aria-label="isOpen ? 'Collapse Current Financial Position' : 'Expand Current Financial Position'" aria-controls="financial-position-content" @click="isOpen = !isOpen">
                    <flux:icon.chevron-up x-show="isOpen" class="size-5" aria-hidden="true" />
                    <flux:icon.chevron-down x-show="!isOpen" class="size-5" aria-hidden="true" />
                    <span class="sr-only" x-text="isOpen ? 'Collapse Current Financial Position' : 'Expand Current Financial Position'"></span>
                </button>
            </div>
        </div>
        <div id="financial-position-content" x-show="isOpen" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading level="3">Total Assets</flux:heading>
                <flux:text>Active accounts</flux:text>
                <p class="break-words text-xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->financialPosition['totalAssets'] }}</p>
            </div>
            <div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading level="3">Allocated Assets</flux:heading>
                <flux:text>Designated to goals</flux:text>
                <p class="break-words text-xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->financialPosition['allocatedAssets'] }}</p>
            </div>
            <div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading level="3">Free Assets</flux:heading>
                <flux:text>Available in active accounts</flux:text>
                <p class="break-words text-xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->financialPosition['freeAssets'] }}</p>
            </div>
        </div>
        @if ($this->financialPosition['overallocatedCount'] > 0)
            <flux:callout variant="warning">
                Allocation warning: {{ $this->financialPosition['overallocatedCount'] === 1 ? '1 account has' : $this->financialPosition['overallocatedCount'].' accounts have' }} allocations above its current balance.
                Overallocated by {{ auth()->user()->currency }} {{ $this->financialPosition['overallocatedTotal'] }}.
                <flux:link :href="route('accounts.index')" wire:navigate>Review allocations</flux:link>
            </flux:callout>
        @endif
        </div>
    </section>

    <section x-data="{ isOpen: true }" x-bind:class="{ 'bg-white dark:bg-zinc-900': isOpen, 'bg-zinc-50 dark:bg-zinc-800': !isOpen }" class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="credit-card-bills-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2" id="credit-card-bills-heading">Credit Card</flux:heading>
                <flux:text>Bills whose due date falls in the selected month. Informational only.</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:link :href="route('credit-cards.index')" wire:navigate>Manage credit cards</flux:link>
                <button type="button" class="inline-flex items-center rounded-lg p-2 text-zinc-700 hover:bg-zinc-100 focus:outline-hidden focus:ring-2 focus:ring-accent dark:text-zinc-200 dark:hover:bg-zinc-800" x-bind:aria-expanded="isOpen" x-bind:aria-label="isOpen ? 'Collapse Credit Card' : 'Expand Credit Card'" aria-controls="credit-card-content" @click="isOpen = !isOpen">
                    <flux:icon.chevron-up x-show="isOpen" class="size-5" aria-hidden="true" />
                    <flux:icon.chevron-down x-show="!isOpen" class="size-5" aria-hidden="true" />
                    <span class="sr-only" x-text="isOpen ? 'Collapse Credit Card' : 'Expand Credit Card'"></span>
                </button>
            </div>
        </div>
        <div id="credit-card-content" x-show="isOpen" class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <caption class="sr-only">Credit card bills due in {{ $selectedMonth }}</caption>
                <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                    <th scope="col" class="px-4 py-3">Card</th><th scope="col" class="px-4 py-3">Cycle</th>
                    <th scope="col" class="px-4 py-3">Due</th><th scope="col" class="px-4 py-3 text-right">Amount</th><th scope="col" class="px-4 py-3">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->creditCardBills as $creditCardBill)
                        @php($card = $creditCardBill['card'])
                        @php($bill = $creditCardBill['bill'])
                        <tr wire:key="dashboard-credit-card-bill-{{ $card->id }}">
                            <td class="px-4 py-3 font-medium">{{ $card->name }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $bill->cycle->start->format('M j') }} – {{ $bill->cycle->end->format('M j') }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $bill->cycle->dueDate->format('M j, Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $bill->total }}</td>
                            <td class="px-4 py-3"><flux:button size="sm" :href="route('credit-cards.show', ['creditCardId' => $card->id, 'month' => $selectedMonth])" wire:navigate>View bill</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6"><flux:text>No credit card bills are due in this month.</flux:text></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

</section>
