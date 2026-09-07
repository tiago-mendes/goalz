<?php

use App\Actions\ResolveMonthlyIncome;
use App\Models\FixedExpense;
use App\Models\MonthlyIncome;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
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
        Gate::authorize('viewAny', FixedExpense::class);
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
        app(ResolveMonthlyIncome::class)->handle(auth()->user(), (int) substr($this->selectedMonth, 0, 4), (int) substr($this->selectedMonth, 5, 2));
        $this->resetValidation();
        unset($this->income, $this->plannedExpenses, $this->plannedTotal, $this->remaining);
    }

    #[Computed]
    public function income(): ?MonthlyIncome
    {
        return auth()->user()->monthlyIncomes()->where('year', (int) substr($this->selectedMonth, 0, 4))
            ->where('month', (int) substr($this->selectedMonth, 5, 2))->first();
    }

    /** @return Collection<int, array{expense: FixedExpense, date: CarbonImmutable}> */
    #[Computed]
    public function plannedExpenses(): Collection
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $this->selectedMonth);

        return auth()->user()->fixedExpenses()->where('is_active', true)
            ->whereDate('start_date', '<=', $month->endOfMonth()->toDateString())
            ->with(['expenseCategory' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id())])
            ->orderBy('day_of_month')->orderBy('name')->orderBy('id')->get()
            ->flatMap(function (FixedExpense $expense) use ($month): array {
                $date = $expense->occurrenceDate($month);

                return $date === null ? [] : [['expense' => $expense, 'date' => $date]];
            });
    }

    #[Computed]
    public function plannedTotal(): string
    {
        $total = BigDecimal::of('0.00');
        foreach ($this->plannedExpenses as $planned) {
            $total = $total->plus($planned['expense']->amount);
        }

        return (string) $total->toScale(2);
    }

    #[Computed]
    public function remaining(): ?string
    {
        return $this->income === null ? null : (string) BigDecimal::of($this->income->amount)->minus($this->plannedTotal)->toScale(2);
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Monthly Overview</flux:heading>
            <flux:text>{{ CarbonImmutable::createFromFormat('!Y-m', $selectedMonth)->format('F Y') }} · Your income and planned fixed costs.</flux:text>
        </div>
        <form wire:submit="openMonth" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="month" label="Month" type="month" min="1000-01" max="9999-12" required />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Open month</flux:button>
        </form>
    </div>

    <div class="grid gap-4 md:grid-cols-3" wire:loading.class="opacity-50" wire:target="openMonth">
        <div class="space-y-3 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading level="2">Monthly Income</flux:heading>
            <p class="break-words text-2xl font-semibold tabular-nums">{{ $this->income ? auth()->user()->currency.' '.$this->income->amount : 'Not configured' }}</p>
            <flux:link :href="route('monthly-income.index')" wire:navigate>Manage income</flux:link>
        </div>
        <div class="space-y-3 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading level="2">Planned Fixed Expenses</flux:heading>
            <p class="break-words text-2xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->plannedTotal }}</p>
            <flux:text>Based on your currently active fixed expenses.</flux:text>
        </div>
        <div class="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading level="2">After Fixed Costs</flux:heading>
            <p class="break-words text-2xl font-semibold tabular-nums">{{ $this->remaining !== null ? auth()->user()->currency.' '.$this->remaining : 'Not available' }}</p>
            <flux:text>Before variable expenses and goal contributions.</flux:text>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg" level="2">Planned Fixed Expenses</flux:heading>
        <flux:button :href="route('fixed-expenses.index')" wire:navigate>Manage fixed expenses</flux:button>
    </div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700" wire:loading.class="opacity-50" wire:target="openMonth">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Planned fixed expenses for {{ $selectedMonth }}</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Category</th>
                    <th scope="col" class="px-4 py-3">Expected date</th>
                    <th scope="col" class="px-4 py-3 text-right">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->plannedExpenses as $planned)
                    <tr wire:key="planned-{{ $selectedMonth }}-{{ $planned['expense']->id }}">
                        <td class="px-4 py-3">{{ $planned['expense']->name }}</td>
                        <td class="px-4 py-3">
                            @if ($category = $planned['expense']->expenseCategory)
                                <span class="inline-flex items-center gap-2">
                                    <span
                                        class="shrink-0"
                                        style="color: {{ $category->safeColor() }}"
                                        aria-hidden="true"
                                    >
                                        <flux:icon :name="$category->safeIcon()" class="size-5" />
                                    </span>
                                <span>{{ $category->name }}</span>
                                </span>
                            @else
                                Category unavailable
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3"><time datetime="{{ $planned['date']->toDateString() }}">{{ $planned['date']->format('M j') }}</time></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $planned['expense']->amount }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8"><flux:text>No planned fixed expenses for this month. Manage your fixed expenses to add recurring costs.</flux:text></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
