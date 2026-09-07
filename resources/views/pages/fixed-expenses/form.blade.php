<?php

use App\Models\FixedExpense;
use App\PaymentSourceSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage fixed expense')] class extends Component {
    #[Locked]
    public ?int $fixedExpenseId = null;
    public string $name = '';
    public $expense_category_id = '';
    public string $amount = '';
    public $day_of_month = '';
    public string $start_date = '';
    public $is_active = true;
    public $payment_source = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', FixedExpense::class);
    }

    public function mount(?int $fixedExpenseId = null): void
    {
        $this->start_date = now()->toDateString();
        if ($fixedExpenseId !== null) {
            $this->fixedExpenseId = $fixedExpenseId;
            $expense = $this->ownedExpense();
            Gate::authorize('view', $expense);
            $this->name = $expense->name;
            $this->expense_category_id = $expense->expense_category_id;
            $this->amount = $expense->amount;
            $this->day_of_month = $expense->day_of_month;
            $this->start_date = $expense->start_date->toDateString();
            $this->is_active = $expense->is_active;
            $this->payment_source = PaymentSourceSelection::fromIds($expense->payment_account_id, $expense->credit_card_id);
        }
    }

    #[Computed]
    public function categories(): Collection
    {
        $currentCategoryId = $this->fixedExpenseId === null ? null : $this->ownedExpense()->expense_category_id;

        return auth()->user()->expenseCategories()
            ->where(function (Builder $query) use ($currentCategoryId): void {
                $query->where('is_active', true);
                if ($currentCategoryId !== null) {
                    $query->orWhere('id', $currentCategoryId);
                }
            })->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function paymentAccounts(): Collection
    {
        $currentAccountId = $this->fixedExpenseId === null ? null : $this->ownedExpense()->payment_account_id;

        return auth()->user()->accounts()->where(function (Builder $query) use ($currentAccountId): void {
            $query->where('is_active', true);
            if ($currentAccountId !== null) {
                $query->orWhere('id', $currentAccountId);
            }
        })->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function paymentCreditCards(): Collection
    {
        $currentCreditCardId = $this->fixedExpenseId === null ? null : $this->ownedExpense()->credit_card_id;

        return auth()->user()->creditCards()->where(function (Builder $query) use ($currentCreditCardId): void {
            $query->where('is_active', true);
            if ($currentCreditCardId !== null) {
                $query->orWhere('id', $currentCreditCardId);
            }
        })->orderBy('name')->orderBy('id')->get();
    }

    public function save(): void
    {
        $expense = $this->fixedExpenseId === null ? auth()->user()->fixedExpenses()->make() : $this->ownedExpense();
        Gate::authorize($expense->exists ? 'update' : 'create', $expense->exists ? $expense : FixedExpense::class);
        $this->name = trim($this->name);

        $categoryRule = Rule::exists('expense_categories', 'id')->where('user_id', auth()->id());
        if (! $expense->exists || ! is_scalar($this->expense_category_id) || (string) $expense->expense_category_id !== (string) $this->expense_category_id) {
            $categoryRule->where('is_active', true);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'expense_category_id' => ['required', 'integer', $categoryRule],
            'payment_source' => ['nullable', 'string', 'max:50'],
            'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/', 'not_in:0,0.0,0.00'],
            'day_of_month' => ['required', 'integer', 'between:1,31'],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31'],
            'is_active' => ['required', 'boolean'],
        ], [
            'expense_category_id.exists' => 'Choose one of your active categories, or keep this fixed expense’s current category.',
            'amount.regex' => 'Enter an amount up to 9999999999999.99 using at most two decimal places.',
            'amount.not_in' => 'The amount must be greater than zero.',
        ]);

        unset($validated['payment_source']);
        $validated += PaymentSourceSelection::resolve(
            auth()->user(),
            $this->payment_source,
            $expense->payment_account_id,
            $expense->credit_card_id,
        );
        $expense->fill($validated)->save();
        session()->flash('status', 'Fixed expense saved.');
        $this->redirectRoute('fixed-expenses.index', navigate: true);
    }

    private function ownedExpense(): FixedExpense
    {
        $expense = auth()->user()->fixedExpenses()->find($this->fixedExpenseId);
        abort_if($expense === null, 404);

        return $expense;
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <flux:button :href="route('fixed-expenses.index')" wire:navigate>Back to fixed expenses</flux:button>
    <flux:heading size="xl" level="1">{{ $fixedExpenseId ? 'Edit fixed expense' : 'Create fixed expense' }}</flux:heading>
    @if ($this->categories->isEmpty())
        <flux:callout>An active category is required. Create or activate a category before saving a fixed expense.</flux:callout>
        <flux:button :href="route('expense-categories.index')" wire:navigate>Manage categories</flux:button>
    @endif
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" maxlength="100" required autocomplete="off" />
        <flux:select wire:model="expense_category_id" label="Category" placeholder="Choose a category" required>
            @foreach ($this->categories as $category)
                <flux:select.option :value="$category->id" wire:key="category-{{ $category->id }}">{{ $category->name }}{{ $category->is_active ? '' : ' (Inactive — current category)' }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="amount" :label="'Amount ('.auth()->user()->currency.')'" inputmode="decimal" placeholder="0.01" required />
        <flux:select wire:model="payment_source" label="Payment source">
            <flux:select.option value="">Not specified</flux:select.option>
            @foreach ($this->paymentAccounts as $account)
                <flux:select.option value="account:{{ $account->id }}" wire:key="fixed-payment-account-{{ $account->id }}">Account — {{ $account->name }}{{ $account->is_active ? '' : ' (Inactive — current source)' }}</flux:select.option>
            @endforeach
            @foreach ($this->paymentCreditCards as $creditCard)
                <flux:select.option value="credit-card:{{ $creditCard->id }}" wire:key="fixed-payment-credit-card-{{ $creditCard->id }}">Credit card — {{ $creditCard->name }}{{ $creditCard->is_active ? '' : ' (Inactive — current source)' }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="day_of_month" label="Day of month" type="number" min="1" max="31" step="1" required />
        <flux:text>For shorter months, the expected day is the last calendar day of that month.</flux:text>
        <flux:input wire:model="start_date" label="Start date" type="date" min="1000-01-01" max="9999-12-31" required />
        <flux:checkbox wire:model="is_active" label="Active fixed expense" />
        <flux:text>This saves a recurring definition. No expense transactions are created.</flux:text>
        <flux:button type="submit" variant="primary" :disabled="$this->categories->isEmpty()" wire:loading.attr="disabled">Save fixed expense</flux:button>
    </form>
</section>
