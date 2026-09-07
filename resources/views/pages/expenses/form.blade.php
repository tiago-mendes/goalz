<?php

use App\Models\Expense;
use App\PaymentSourceSelection;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage expense')] class extends Component {
    #[Locked]
    public ?int $expenseId = null;

    #[Locked]
    public ?int $duplicateId = null;

    #[Locked]
    public ?string $returnMonth = null;

    public bool $showDuplicate = false;
    public string $name = '';
    public $expense_category_id = '';
    public string $amount = '';
    public string $expense_date = '';
    public ?string $description = null;
    public $payment_source = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', Expense::class);
    }

    public function mount(?int $expenseId = null): void
    {
        $month = request()->query('month');
        if (Validator::make(['month' => $month], ['month' => ['required', 'string', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12']])->passes()) {
            $this->returnMonth = $month;
        }
        $date = $this->returnMonth === null ? CarbonImmutable::now() : CarbonImmutable::createFromFormat('!Y-m', $this->returnMonth);
        $this->expense_date = $date->setDay(min(now()->day, $date->daysInMonth))->toDateString();
        if ($expenseId !== null) {
            $this->expenseId = $expenseId;
            $expense = $this->ownedExpense();
            Gate::authorize('view', $expense);
            $this->name = $expense->name;
            $this->expense_category_id = $expense->expense_category_id;
            $this->amount = $expense->amount;
            $this->expense_date = $expense->expense_date->toDateString();
            $this->description = $expense->description;
            $this->payment_source = PaymentSourceSelection::fromIds($expense->payment_account_id, $expense->credit_card_id);
        }
    }

    #[Computed]
    public function categories(): Collection
    {
        $currentCategoryId = $this->expenseId === null ? null : $this->ownedExpense()->expense_category_id;

        return auth()->user()->expenseCategories()->where(function (Builder $query) use ($currentCategoryId): void {
            $query->where('is_active', true);
            if ($currentCategoryId !== null) {
                $query->orWhere('id', $currentCategoryId);
            }
        })->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function paymentAccounts(): Collection
    {
        $currentAccountId = $this->expenseId === null ? null : $this->ownedExpense()->payment_account_id;

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
        $currentCreditCardId = $this->expenseId === null ? null : $this->ownedExpense()->credit_card_id;

        return auth()->user()->creditCards()->where(function (Builder $query) use ($currentCreditCardId): void {
            $query->where('is_active', true);
            if ($currentCreditCardId !== null) {
                $query->orWhere('id', $currentCreditCardId);
            }
        })->orderBy('name')->orderBy('id')->get();
    }

    public function save(): void
    {
        $expense = $this->expenseId === null ? null : $this->ownedExpense();
        $data = $this->validatedFields($expense);
        if ($expense !== null) {
            $expense->fill($data)->save();
            $this->finish('Expense saved.');

            return;
        }

        $candidate = auth()->user()->expenses()->whereNotNull('deleted_by_user_at')->whereNull('fixed_expense_id')
            ->where('expense_category_id', $data['expense_category_id'])->whereDate('expense_date', $data['expense_date'])
            ->orderByDesc('deleted_by_user_at')->orderByDesc('id')->get()
            ->first(fn (Expense $expense): bool => $this->matches($expense, $data));
        if ($candidate !== null) {
            $this->duplicateId = $candidate->id;
            $this->showDuplicate = true;

            return;
        }

        $this->createManual($data);
    }

    public function restoreDuplicate(): void
    {
        $data = $this->validatedFields();
        $candidate = $this->checkedCandidate($data);
        Gate::authorize('restore', $candidate);
        $candidate->deleted_by_user_at = null;
        $candidate->save();
        $this->finish('Expense restored.');
    }

    public function createAnyway(): void
    {
        $data = $this->validatedFields();
        $this->checkedCandidate($data);
        $this->createManual($data);
    }

    public function cancelDuplicate(): void
    {
        $this->duplicateId = null;
        $this->showDuplicate = false;
    }

    /** @return array{name: string, expense_category_id: int, payment_account_id: ?int, credit_card_id: ?int, amount: string, expense_date: string, description: ?string} */
    private function validatedFields(?Expense $expense = null): array
    {
        Gate::authorize($expense === null ? 'create' : 'update', $expense ?? Expense::class);
        $this->name = trim($this->name);
        $this->description = trim($this->description ?? '');
        $this->description = $this->description === '' ? null : $this->description;
        $categoryRule = Rule::exists('expense_categories', 'id')->where('user_id', auth()->id());
        if ($expense === null || ! is_scalar($this->expense_category_id) || (string) $expense->expense_category_id !== (string) $this->expense_category_id) {
            $categoryRule->where('is_active', true);
        }
        $dateRules = ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31'];
        if ($expense?->fixed_expense_id !== null) {
            $period = \Carbon\CarbonImmutable::create($expense->occurrence_year, $expense->occurrence_month, 1);
            $dateRules[] = 'after_or_equal:'.$period->startOfMonth()->toDateString();
            $dateRules[] = 'before_or_equal:'.$period->endOfMonth()->toDateString();
        }
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'expense_category_id' => ['required', 'integer', $categoryRule],
            'payment_source' => ['nullable', 'string', 'max:50'],
            'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/', 'not_in:0,0.0,0.00'],
            'expense_date' => $dateRules,
            'description' => ['nullable', 'string', 'max:10000'],
        ], [
            'expense_category_id.exists' => 'Choose one of your active categories, or keep this expense’s current category.',
            'amount.regex' => 'Enter an amount up to 9999999999999.99 using at most two decimal places.',
            'amount.not_in' => 'The amount must be greater than zero.',
            'expense_date.date_format' => 'Enter a valid date. Recurring expenses must stay within their occurrence month.',
        ]);
        $data['expense_category_id'] = (int) $data['expense_category_id'];
        $data['amount'] = (string) BigDecimal::of($data['amount'])->toScale(2);
        unset($data['payment_source']);
        $data += PaymentSourceSelection::resolve(
            auth()->user(),
            $this->payment_source,
            $expense?->payment_account_id,
            $expense?->credit_card_id,
        );

        return $data;
    }

    /** @param array{name: string, expense_category_id: int, payment_account_id: ?int, credit_card_id: ?int, amount: string, expense_date: string, description: ?string} $data */
    private function matches(Expense $expense, array $data): bool
    {
        return trim($expense->name) === $data['name']
            && $expense->expense_category_id === $data['expense_category_id']
            && $expense->payment_account_id === $data['payment_account_id']
            && $expense->credit_card_id === $data['credit_card_id']
            && $expense->amount === $data['amount']
            && $expense->expense_date->toDateString() === $data['expense_date']
            && trim($expense->description ?? '') === ($data['description'] ?? '');
    }

    /** @param array{name: string, expense_category_id: int, payment_account_id: ?int, credit_card_id: ?int, amount: string, expense_date: string, description: ?string} $data */
    private function checkedCandidate(array $data): Expense
    {
        abort_if($this->expenseId !== null || $this->duplicateId === null, 404);
        $candidate = auth()->user()->expenses()->whereNotNull('deleted_by_user_at')->whereNull('fixed_expense_id')->find($this->duplicateId);
        abort_if($candidate === null, 404);
        if (! $this->matches($candidate, $data)) {
            $this->cancelDuplicate();
            throw ValidationException::withMessages(['name' => 'The expense details changed. Save again to check for a matching deleted expense.']);
        }

        return $candidate;
    }

    /** @param array{name: string, expense_category_id: int, payment_account_id: ?int, credit_card_id: ?int, amount: string, expense_date: string, description: ?string} $data */
    private function createManual(array $data): void
    {
        $expense = auth()->user()->expenses()->make($data);
        $expense->fixed_expense_id = null;
        $expense->occurrence_year = null;
        $expense->occurrence_month = null;
        $expense->deleted_by_user_at = null;
        $expense->save();
        $this->finish('Expense created.');
    }

    private function ownedExpense(): Expense
    {
        $expense = auth()->user()->expenses()->whereNull('deleted_by_user_at')->find($this->expenseId);
        abort_if($expense === null, 404);

        return $expense;
    }

    private function finish(string $message): void
    {
        $this->cancelDuplicate();
        session()->flash('status', $message);
        $this->redirectRoute('expenses.index', ['month' => $this->returnMonth], navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <flux:button :href="route('expenses.index', ['month' => $returnMonth])" wire:navigate>Back to expenses</flux:button>
    <flux:heading size="xl" level="1">{{ $expenseId ? 'Edit expense' : 'Add expense' }}</flux:heading>
    @if ($this->categories->isEmpty())
        <flux:callout>An active category is required. Create or activate a category before adding an expense.</flux:callout>
        <flux:button :href="route('expense-categories.index')" wire:navigate>Manage categories</flux:button>
    @endif
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" maxlength="100" required />
        <flux:select wire:model="expense_category_id" label="Category" placeholder="Choose a category" required>
            @foreach ($this->categories as $category)
                <flux:select.option :value="$category->id" wire:key="category-{{ $category->id }}">{{ $category->name }}{{ $category->is_active ? '' : ' (Inactive — current category)' }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="amount" :label="'Amount ('.auth()->user()->currency.')'" inputmode="decimal" placeholder="0.01" required />
        <flux:input wire:model="expense_date" label="Expense date" type="date" min="1000-01-01" max="9999-12-31" required />
        <flux:select wire:model="payment_source" label="Payment source">
            <flux:select.option value="">Not specified</flux:select.option>
            @foreach ($this->paymentAccounts as $account)
                <flux:select.option value="account:{{ $account->id }}" wire:key="payment-account-{{ $account->id }}">Account — {{ $account->name }}{{ $account->is_active ? '' : ' (Inactive — current source)' }}</flux:select.option>
            @endforeach
            @foreach ($this->paymentCreditCards as $creditCard)
                <flux:select.option value="credit-card:{{ $creditCard->id }}" wire:key="payment-credit-card-{{ $creditCard->id }}">Credit card — {{ $creditCard->name }}{{ $creditCard->is_active ? '' : ' (Inactive — current source)' }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:textarea wire:model="description" label="Description (optional)" maxlength="10000" />
        <flux:button type="submit" variant="primary" :disabled="$this->categories->isEmpty()" wire:loading.attr="disabled">Save expense</flux:button>
    </form>
    <flux:modal wire:model="showDuplicate" :dismissible="false" :closable="false" class="md:w-120">
        <div class="space-y-6">
            <flux:heading size="lg">A matching deleted expense already exists.</flux:heading>
            <flux:text>{{ $name }} · {{ auth()->user()->currency }} {{ $amount }} · {{ $expense_date }}</flux:text>
            <flux:text>Restore the deleted expense or keep it deleted and create a new one.</flux:text>
            <div class="flex flex-wrap gap-3">
                <flux:button wire:click="restoreDuplicate" variant="primary" wire:loading.attr="disabled">Restore expense</flux:button>
                <flux:button wire:click="createAnyway" wire:loading.attr="disabled">Create a new expense anyway</flux:button>
                <flux:button wire:click="cancelDuplicate" variant="ghost">Cancel</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
