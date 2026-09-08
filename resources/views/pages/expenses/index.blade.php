<?php

use App\Actions\MaterializeFixedExpensesForMonth;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Expenses')] class extends Component {
    #[Url(history: true)]
    public string $month = '';

    #[Locked]
    public string $selectedMonth = '';

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $category = '';

    #[Url(history: true)]
    public string $source = '';

    #[Url(as: 'payment_source', history: true)]
    public string $paymentSource = '';

    #[Url(history: true)]
    public string $sort = 'date';

    #[Url(history: true)]
    public string $direction = 'asc';

    public bool $showDeleted = false;

    public function boot(): void
    {
        Gate::authorize('viewAny', Expense::class);
    }

    public function mount(): void
    {
        $month = request()->query('month', $this->month);
        $valid = Validator::make(['month' => $month], ['month' => ['required', 'string', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12']])->passes();
        $this->month = $valid ? $month : now()->format('Y-m');
        $this->normalizeFilters();
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
        foreach ($this->unfilteredMonthQuery()->whereNull('deleted_by_user_at')->get() as $expense) {
            $total = $total->plus($expense->amount);
        }

        return (string) $total->toScale(2);
    }

    #[Computed]
    public function categories(): Collection
    {
        return auth()->user()->expenseCategories()->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function accounts(): Collection
    {
        return auth()->user()->accounts()->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function creditCards(): Collection
    {
        return auth()->user()->creditCards()->orderBy('name')->orderBy('id')->get();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->category = '';
        $this->source = '';
        $this->paymentSource = '';
        $this->sort = 'date';
        $this->direction = 'asc';
        $this->refreshExpenses();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'source', 'paymentSource', 'sort', 'direction'], true)) {
            $this->normalizeFilters();
            $this->refreshExpenses();
        }
    }

    public function hasActiveFilters(): bool
    {
        return trim($this->search) !== '' || $this->category !== '' || $this->source !== '' || $this->paymentSource !== '';
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
        $query = $this->unfilteredMonthQuery()->with([
            'expenseCategory' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id()),
            'paymentAccount' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id()),
            'creditCard' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id()),
        ]);
        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%');
            });
        }
        if ($this->category !== '') {
            $query->where('expense_category_id', $this->category);
        }
        if ($this->source === 'manual') {
            $query->whereNull('fixed_expense_id');
        } elseif ($this->source === 'recurring') {
            $query->whereNotNull('fixed_expense_id');
        }
        if ($this->paymentSource === 'none') {
            $query->whereNull('payment_account_id')->whereNull('credit_card_id');
        } elseif ($this->paymentSource === 'account') {
            $query->whereNotNull('payment_account_id');
        } elseif ($this->paymentSource === 'card') {
            $query->whereNotNull('credit_card_id');
        } elseif (str_starts_with($this->paymentSource, 'account:')) {
            $query->where('payment_account_id', (int) str_replace('account:', '', $this->paymentSource));
        } elseif (str_starts_with($this->paymentSource, 'card:')) {
            $query->where('credit_card_id', (int) str_replace('card:', '', $this->paymentSource));
        }
        if ($this->sort === 'category') {
            $query->orderBy(ExpenseCategory::query()->select('name')
                ->whereColumn('expense_categories.id', 'expenses.expense_category_id'), $this->direction);
        } else {
            $query->orderBy(match ($this->sort) {
                'name' => 'name',
                'amount' => 'amount',
                default => 'expense_date',
            }, $this->direction);
        }

        return $query->orderBy('id');
    }

    /** @return HasMany<Expense, \App\Models\User> */
    private function unfilteredMonthQuery(): HasMany
    {
        return auth()->user()->expenses()->forMonth(CarbonImmutable::createFromFormat('!Y-m', $this->selectedMonth));
    }

    private function normalizeFilters(): void
    {
        $this->search = trim($this->search);
        $this->category = ctype_digit($this->category) && auth()->user()->expenseCategories()->whereKey((int) $this->category)->exists()
            ? (string) (int) $this->category : '';
        $this->source = in_array($this->source, ['manual', 'recurring'], true) ? $this->source : '';
        $this->paymentSource = $this->normalizePaymentSource($this->paymentSource);
        $this->sort = in_array($this->sort, ['date', 'name', 'amount', 'category'], true) ? $this->sort : 'date';
        $this->direction = in_array($this->direction, ['asc', 'desc'], true) ? $this->direction : 'asc';
    }

    private function normalizePaymentSource(string $paymentSource): string
    {
        if (in_array($paymentSource, ['', 'none', 'account', 'card'], true)) {
            return $paymentSource;
        }
        if (preg_match('/\A(account|card):([1-9][0-9]*)\z/', $paymentSource, $matches) !== 1) {
            return '';
        }
        $relation = $matches[1] === 'account' ? 'accounts' : 'creditCards';

        return auth()->user()->{$relation}()->whereKey((int) $matches[2])->exists()
            ? $matches[1].':'.(int) $matches[2] : '';
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
    <div class="grid gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:grid-cols-2 sm:items-end lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)_minmax(0,.75fr)_minmax(0,1.1fr)_minmax(0,.85fr)_minmax(0,.8fr)_auto]">
        <flux:input class="min-w-0" wire:model.live.debounce.350ms="search" label="Search" placeholder="Search expenses..." type="search" />
        <flux:select wire:model.live="category" label="Category" class="min-w-0">
            <flux:select.option value="">All categories</flux:select.option>
            @foreach ($this->categories as $categoryOption)
                <flux:select.option value="{{ $categoryOption->id }}">{{ $categoryOption->name }}{{ $categoryOption->is_active ? '' : ' (Inactive)' }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="source" label="Source" class="min-w-0">
            <flux:select.option value="">All</flux:select.option>
            <flux:select.option value="manual">Manual</flux:select.option>
            <flux:select.option value="recurring">Recurring</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="paymentSource" label="Payment Source" class="min-w-0">
            <flux:select.option value="">All payment sources</flux:select.option>
            <flux:select.option value="none">Not specified</flux:select.option>
            <flux:select.option value="account">Accounts</flux:select.option>
            @foreach ($this->accounts as $account)
                <flux:select.option value="account:{{ $account->id }}">Account: {{ $account->name }}</flux:select.option>
            @endforeach
            <flux:select.option value="card">Credit Cards</flux:select.option>
            @foreach ($this->creditCards as $creditCard)
                <flux:select.option value="card:{{ $creditCard->id }}">Credit Card: {{ $creditCard->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="sort" label="Sort" class="min-w-0">
            <flux:select.option value="date">Date</flux:select.option>
            <flux:select.option value="name">Name</flux:select.option>
            <flux:select.option value="amount">Amount</flux:select.option>
            <flux:select.option value="category">Category</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="direction" label="Direction" class="min-w-0">
            <flux:select.option value="asc">Ascending</flux:select.option>
            <flux:select.option value="desc">Descending</flux:select.option>
        </flux:select>
        @if ($this->hasActiveFilters())
            <flux:button wire:click="clearFilters">Clear filters</flux:button>
        @endif
    </div>
    <flux:text>{{ $this->expenses->count() }} {{ $this->expenses->count() === 1 ? 'expense' : 'expenses' }} shown</flux:text>
    <div class="space-y-2 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <flux:heading size="lg">{{ CarbonImmutable::createFromFormat('!Y-m', $selectedMonth)->format('F Y') }}</flux:heading>
        <flux:text>Total Expenses</flux:text>
        <p class="break-words text-2xl font-semibold tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$this->total" /></p>
    </div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700" wire:loading.class="opacity-50" wire:target="openMonth">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your expenses for {{ $selectedMonth }}</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                <th scope="col" class="px-4 py-3">Name</th><th scope="col" class="px-4 py-3">Category</th>
                <th scope="col" class="px-4 py-3">Amount</th><th scope="col" class="px-4 py-3">Date</th>
                <th scope="col" class="px-4 py-3">Source</th><th scope="col" class="px-4 py-3">Payment Source</th><th scope="col" class="px-4 py-3">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->expenses as $expense)
                    <tr wire:key="expense-{{ $expense->id }}">
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
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$expense->amount" /></td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $expense->expense_date->toDateString() }}</td>
                        <td class="px-4 py-3"><flux:badge :color="$expense->fixed_expense_id === null ? 'zinc' : 'green'">{{ $expense->fixed_expense_id === null ? 'Manual' : 'Recurring' }}</flux:badge></td>
                        <td class="px-4 py-3">{{ $expense->paymentSourceName() }}</td>
                        <td class="px-4 py-3"><div class="flex gap-2">
                            <flux:button size="sm" :href="route('expenses.edit', ['expenseId' => $expense->id, 'month' => $selectedMonth])" :aria-label="'Edit '.$expense->name" wire:navigate>Edit</flux:button>
                            <flux:button size="sm" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="Delete this expense? You can restore it later." :aria-label="'Delete '.$expense->name" wire:loading.attr="disabled">Delete</flux:button>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8">
                        <flux:text>{{ $this->hasActiveFilters() ? 'No expenses match your current filters.' : 'No expenses for this month. Add an expense to record a cost.' }}</flux:text>
                        @if ($this->hasActiveFilters()) <flux:button size="sm" wire:click="clearFilters">Clear filters</flux:button> @endif
                    </td></tr>
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
                    <th scope="col" class="px-4 py-3">Source</th><th scope="col" class="px-4 py-3">Payment Source</th><th scope="col" class="px-4 py-3">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->deletedExpenses as $expense)
                        <tr wire:key="deleted-expense-{{ $expense->id }}">
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
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$expense->amount" /></td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $expense->expense_date->toDateString() }}</td>
                            <td class="px-4 py-3"><flux:badge>{{ $expense->fixed_expense_id === null ? 'Manual' : 'Recurring' }}</flux:badge></td>
                            <td class="px-4 py-3">{{ $expense->paymentSourceName() }}</td>
                            <td class="px-4 py-3"><flux:button size="sm" wire:click="restoreExpense({{ $expense->id }})" :aria-label="'Restore '.$expense->name" wire:loading.attr="disabled">Restore</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6">No deleted expenses for this month.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</section>
