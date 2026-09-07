<?php

use App\Actions\MaterializeFixedExpensesForMonth;
use App\Actions\ResolveMonthlyIncome;
use App\Models\Expense;
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
            <flux:heading size="xl" level="1">Monthly Overview</flux:heading>
            <flux:text>{{ CarbonImmutable::createFromFormat('!Y-m', $selectedMonth)->format('F Y') }} · Your income and expenses.</flux:text>
        </div>
        <form wire:submit="openMonth" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="month" label="Month" type="month" min="1000-01" max="9999-12" required />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Open month</flux:button>
        </form>
    </div>

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
        <flux:heading size="lg" level="2">Expenses</flux:heading>
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
</section>
