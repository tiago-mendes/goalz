<?php

use App\Actions\MaterializeFixedExpensesForMonth;
use App\Models\Expense;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Expenses')] class extends Component {
    public string $month = '';

    #[Locked]
    public string $selectedMonth = '';

    public bool $showDeleted = false;

    public function boot(): void
    {
        Gate::authorize('viewAny', Expense::class);
    }

    public function mount(): void
    {
        $month = request()->query('month');
        $valid = Validator::make(['month' => $month], ['month' => ['required', 'string', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12']])->passes();
        $this->month = $valid ? $month : now()->format('Y-m');
        $this->openMonth();
    }

    public function openMonth(): void
    {
        $this->validate(['month' => ['required', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month);
        app(MaterializeFixedExpensesForMonth::class)->handle(auth()->user(), $month->year, $month->month);
        $this->selectedMonth = $this->month;
        $this->showDeleted = false;
        $this->resetValidation();
        $this->refreshExpenses();
    }

    #[Computed]
    public function expenses(): Collection
    {
        return $this->monthQuery()->whereNull('deleted_by_user_at')->get();
    }

    #[Computed]
    public function deletedExpenses(): Collection
    {
        return $this->monthQuery()->whereNotNull('deleted_by_user_at')->get();
    }

    #[Computed]
    public function deletedCount(): int
    {
        return $this->monthQuery()->whereNotNull('deleted_by_user_at')->count();
    }

    #[Computed]
    public function total(): string
    {
        $total = BigDecimal::of('0.00');
        foreach ($this->expenses as $expense) {
            $total = $total->plus($expense->amount);
        }

        return (string) $total->toScale(2);
    }

    public function deleteExpense(int $expenseId): void
    {
        $expense = auth()->user()->expenses()->whereNull('deleted_by_user_at')->find($expenseId);
        abort_if($expense === null, 404);
        Gate::authorize('delete', $expense);
        $expense->deleted_by_user_at = now();
        $expense->save();
        $this->refreshExpenses();
        session()->flash('status', 'Expense deleted. You can restore it from deleted expenses.');
    }

    public function restoreExpense(int $expenseId): void
    {
        $expense = auth()->user()->expenses()->whereNotNull('deleted_by_user_at')->find($expenseId);
        abort_if($expense === null, 404);
        Gate::authorize('restore', $expense);
        $expense->deleted_by_user_at = null;
        $expense->save();
        $this->refreshExpenses();
        session()->flash('status', 'Expense restored.');
    }

    /** @return HasMany<Expense, \App\Models\User> */
    private function monthQuery(): HasMany
    {
        return auth()->user()->expenses()->forMonth(CarbonImmutable::createFromFormat('!Y-m', $this->selectedMonth))
            ->with(['expenseCategory' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id())])
            ->orderBy('expense_date')->orderBy('id');
    }

    private function refreshExpenses(): void
    {
        unset($this->expenses, $this->deletedExpenses, $this->deletedCount, $this->total);
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Expenses</flux:heading>
        <flux:button variant="primary" :href="route('expenses.create', ['month' => $selectedMonth])" wire:navigate>Add expense</flux:button>
    </div>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif
    <form wire:submit="openMonth" class="flex flex-wrap items-end gap-3">
        <flux:input wire:model="month" label="Month" type="month" min="1000-01" max="9999-12" required />
        <flux:button type="submit" wire:loading.attr="disabled">Open month</flux:button>
    </form>
    <div class="space-y-2 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <flux:heading size="lg">{{ CarbonImmutable::createFromFormat('!Y-m', $selectedMonth)->format('F Y') }}</flux:heading>
        <flux:text>Total Expenses</flux:text>
        <p class="break-words text-2xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->total }}</p>
    </div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700" wire:loading.class="opacity-50" wire:target="openMonth">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your expenses for {{ $selectedMonth }}</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                <th scope="col" class="px-4 py-3">Name</th><th scope="col" class="px-4 py-3">Category</th>
                <th scope="col" class="px-4 py-3">Amount</th><th scope="col" class="px-4 py-3">Date</th>
                <th scope="col" class="px-4 py-3">Source</th><th scope="col" class="px-4 py-3">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->expenses as $expense)
                    <tr wire:key="expense-{{ $expense->id }}">
                        <td class="px-4 py-3">{{ $expense->name }}</td>
                        <td class="px-4 py-3">{{ $expense->expenseCategory?->name ?? 'Category unavailable' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ auth()->user()->currency }} {{ $expense->amount }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $expense->expense_date->toDateString() }}</td>
                        <td class="px-4 py-3"><flux:badge :color="$expense->fixed_expense_id === null ? 'zinc' : 'green'">{{ $expense->fixed_expense_id === null ? 'Manual' : 'Recurring' }}</flux:badge></td>
                        <td class="px-4 py-3"><div class="flex gap-2">
                            <flux:button size="sm" :href="route('expenses.edit', ['expenseId' => $expense->id, 'month' => $selectedMonth])" :aria-label="'Edit '.$expense->name" wire:navigate>Edit</flux:button>
                            <flux:button size="sm" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="Delete this expense? You can restore it later." :aria-label="'Delete '.$expense->name" wire:loading.attr="disabled">Delete</flux:button>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8"><flux:text>No expenses for this month. Add an expense to record a cost.</flux:text></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <flux:button wire:click="$toggle('showDeleted')" :aria-expanded="$showDeleted ? 'true' : 'false'">{{ $showDeleted ? 'Hide' : 'Show' }} deleted expenses ({{ $this->deletedCount }})</flux:button>
    @if ($showDeleted)
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-left text-sm">
                <caption class="px-4 py-3 text-left font-medium">Deleted expenses for {{ $selectedMonth }}</caption>
                <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                    <th scope="col" class="px-4 py-3">Name</th><th scope="col" class="px-4 py-3">Category</th>
                    <th scope="col" class="px-4 py-3">Amount</th><th scope="col" class="px-4 py-3">Date</th>
                    <th scope="col" class="px-4 py-3">Source</th><th scope="col" class="px-4 py-3">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->deletedExpenses as $expense)
                        <tr wire:key="deleted-expense-{{ $expense->id }}">
                            <td class="px-4 py-3">{{ $expense->name }}</td>
                            <td class="px-4 py-3">{{ $expense->expenseCategory?->name ?? 'Category unavailable' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ auth()->user()->currency }} {{ $expense->amount }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $expense->expense_date->toDateString() }}</td>
                            <td class="px-4 py-3"><flux:badge>{{ $expense->fixed_expense_id === null ? 'Manual' : 'Recurring' }}</flux:badge></td>
                            <td class="px-4 py-3"><flux:button size="sm" wire:click="restoreExpense({{ $expense->id }})" :aria-label="'Restore '.$expense->name" wire:loading.attr="disabled">Restore</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6">No deleted expenses for this month.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</section>
