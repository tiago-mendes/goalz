<?php

use App\Models\FixedExpense;
use App\Models\ExpenseCategory;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Fixed Expenses')] class extends Component {
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $category = '';

    #[Url(history: true)]
    public string $status = '';

    #[Url(as: 'payment_source', history: true)]
    public string $paymentSource = '';

    #[Url(history: true)]
    public string $sort = 'name';

    #[Url(history: true)]
    public string $direction = 'asc';

    public function boot(): void
    {
        Gate::authorize('viewAny', FixedExpense::class);
    }

    public function mount(): void
    {
        $this->normalizeFilters();
    }

    #[Computed]
    public function fixedExpenses(): LengthAwarePaginator
    {
        $query = auth()->user()->fixedExpenses()
            ->with([
                'expenseCategory' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id()),
                'paymentAccount' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id()),
                'creditCard' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id()),
            ]);
        $search = trim($this->search);

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if ($this->category !== '') {
            $query->where('expense_category_id', $this->category);
        }

        if ($this->status === 'active') {
            $query->where('is_active', true);
        } elseif ($this->status === 'inactive') {
            $query->where('is_active', false);
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

        $sortColumn = match ($this->sort) {
            'amount' => 'amount',
            'day' => 'day_of_month',
            'start_date' => 'start_date',
            'status' => 'is_active',
            default => 'name',
        };
        if ($this->sort === 'category') {
            $query->orderBy(ExpenseCategory::query()->select('name')
                ->whereColumn('expense_categories.id', 'fixed_expenses.expense_category_id'), $this->direction);
        } else {
            $query->orderBy($sortColumn, $this->direction);
        }

        return $query->orderBy('id')->paginate(20);
    }

    #[Computed]
    public function activeFixedExpensesTotal(): string
    {
        $total = BigDecimal::of('0.00');

        foreach (auth()->user()->fixedExpenses()->where('is_active', true)->get(['amount']) as $fixedExpense) {
            $total = $total->plus($fixedExpense->amount);
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
        $this->status = '';
        $this->paymentSource = '';
        $this->sort = 'name';
        $this->direction = 'asc';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'status', 'paymentSource', 'sort', 'direction'], true)) {
            $this->normalizeFilters();
            $this->resetPage();
        }
    }

    public function hasActiveFilters(): bool
    {
        return trim($this->search) !== '' || $this->category !== '' || $this->status !== '' || $this->paymentSource !== '';
    }

    private function normalizeFilters(): void
    {
        $this->search = trim($this->search);
        $this->category = ctype_digit($this->category) && auth()->user()->expenseCategories()->whereKey((int) $this->category)->exists()
            ? (string) (int) $this->category : '';
        $this->status = in_array($this->status, ['active', 'inactive'], true) ? $this->status : '';
        $this->paymentSource = $this->normalizePaymentSource($this->paymentSource);
        $this->sort = in_array($this->sort, ['name', 'amount', 'day', 'start_date', 'category', 'status'], true) ? $this->sort : 'name';
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

    public function setActive(int $fixedExpenseId, bool $active): void
    {
        $expense = auth()->user()->fixedExpenses()->find($fixedExpenseId);
        abort_if($expense === null, 404);
        Gate::authorize('update', $expense);
        $expense->is_active = $active;
        $expense->save();
        unset($this->activeFixedExpensesTotal, $this->fixedExpenses);
        session()->flash('status', $active ? 'Fixed expense activated.' : 'Fixed expense deactivated.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Fixed Expenses</flux:heading>
        <flux:button variant="primary" :href="route('fixed-expenses.create')" wire:navigate>Create fixed expense</flux:button>
    </div>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif
    <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <div class="grid gap-3 sm:grid-cols-2 sm:items-end lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)_minmax(0,.75fr)_minmax(0,1.1fr)_minmax(0,.85fr)_minmax(0,.8fr)_auto]">
        <flux:input class="min-w-0" wire:model.live.debounce.350ms="search" label="Search" placeholder="Search fixed expenses..." type="search" />
        <flux:select wire:model.live="category" label="Category" class="min-w-0">
            <flux:select.option value="">All categories</flux:select.option>
            @foreach ($this->categories as $categoryOption)
                <flux:select.option value="{{ $categoryOption->id }}">{{ $categoryOption->name }}{{ $categoryOption->is_active ? '' : ' (Inactive)' }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" label="Status" class="min-w-0">
            <flux:select.option value="">All</flux:select.option>
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="inactive">Inactive</flux:select.option>
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
            <flux:select.option value="name">Name</flux:select.option>
            <flux:select.option value="amount">Amount</flux:select.option>
            <flux:select.option value="day">Day</flux:select.option>
            <flux:select.option value="start_date">Start date</flux:select.option>
            <flux:select.option value="category">Category</flux:select.option>
            <flux:select.option value="status">Status</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="direction" label="Direction" class="min-w-0">
            <flux:select.option value="asc">Ascending</flux:select.option>
            <flux:select.option value="desc">Descending</flux:select.option>
        </flux:select>
        @if ($this->hasActiveFilters())
            <flux:button wire:click="clearFilters">Clear filters</flux:button>
        @endif
        </div>
    </div>
    <div class="space-y-1 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="lg">Total Fixed Expenses</flux:heading>
        <p class="text-2xl font-semibold tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$this->activeFixedExpensesTotal" /></p>
        <flux:text class="text-sm">Active recurring templates</flux:text>
    </div>
    <flux:text>{{ $this->fixedExpenses->total() }} {{ $this->fixedExpenses->total() === 1 ? 'fixed expense' : 'fixed expenses' }}</flux:text>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your recurring expense definitions</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Category</th>
                    <th scope="col" class="px-4 py-3">Amount</th>
                    <th scope="col" class="px-4 py-3">Day</th>
                    <th scope="col" class="px-4 py-3">Start date</th>
                    <th scope="col" class="px-4 py-3">Payment Source</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->fixedExpenses as $expense)
                    <tr wire:key="fixed-expense-{{ $expense->id }}">
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
                        <td class="whitespace-nowrap px-4 py-3"><x-money :currency="auth()->user()->currency" :amount="$expense->amount" /></td>
                        <td class="px-4 py-3">{{ $expense->day_of_month }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $expense->start_date->toDateString() }}</td>
                        <td class="px-4 py-3">{{ $expense->paymentSourceName() }}</td>
                        <td class="px-4 py-3"><flux:badge :color="$expense->is_active ? 'green' : 'zinc'">{{ $expense->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <flux:button size="sm" :href="route('fixed-expenses.edit', $expense->id)" :aria-label="'Edit '.$expense->name" wire:navigate>Edit</flux:button>
                                <flux:button size="sm" wire:click="setActive({{ $expense->id }}, {{ $expense->is_active ? 'false' : 'true' }})" wire:confirm="Change this fixed expense's active status?" wire:loading.attr="disabled" :aria-label="($expense->is_active ? 'Deactivate ' : 'Activate ').$expense->name">{{ $expense->is_active ? 'Deactivate' : 'Activate' }}</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6">
                        <flux:text>{{ $this->hasActiveFilters() ? 'No fixed expenses match your current filters.' : 'No fixed expenses yet. Create your first recurring definition to get started.' }}</flux:text>
                        @if ($this->hasActiveFilters()) <flux:button size="sm" wire:click="clearFilters">Clear filters</flux:button> @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->fixedExpenses->links() }}
</section>
