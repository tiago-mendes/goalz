<?php

use App\AccountType;
use App\Models\Account;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage account')] class extends Component {
    #[Locked]
    public ?int $accountId = null;
    public string $name = '';
    public string $type = AccountType::Checking->value;
    public string $current_balance = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', Account::class);
    }

    public function mount(?int $accountId = null): void
    {
        if ($accountId !== null) {
            $account = auth()->user()->accounts()->find($accountId);
            abort_if($account === null, 404);
            Gate::authorize('view', $account);
            $this->accountId = $account->id;
            $this->name = $account->name;
            $this->type = $account->type->value;
            $this->current_balance = $account->current_balance;
        }
    }

    public function save(): void
    {
        $account = $this->accountId === null
            ? auth()->user()->accounts()->make()
            : auth()->user()->accounts()->find($this->accountId);
        abort_if($account === null, 404);
        Gate::authorize($account->exists ? 'update' : 'create', $account->exists ? $account : Account::class);

        $this->name = trim($this->name);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('accounts', 'name')->where('user_id', auth()->id())->ignore($account)],
            'type' => ['required', 'string', Rule::in(array_column(AccountType::cases(), 'value'))],
            'current_balance' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/'],
        ], [
            'current_balance.regex' => 'Enter a balance from 0.00 up to 9999999999999.99 using at most two decimal places.',
        ]);

        if ($account->exists) {
            $balanceChanged = ! BigDecimal::of($account->current_balance)->isEqualTo(BigDecimal::of($validated['current_balance']));
            $account->fill($validated);
            if ($balanceChanged) {
                $account->balance_updated_at = now();
            }
        } else {
            $account->fill($validated);
            $account->is_active = true;
            $account->balance_updated_at = now();
        }

        try {
            $account->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'The name has already been taken.']);
        }

        session()->flash('status', 'Account saved.');
        $this->redirectRoute('accounts.index', navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <flux:button :href="route('accounts.index')" wire:navigate>Back to accounts</flux:button>
    <flux:heading size="xl" level="1">{{ $accountId ? 'Edit account' : 'Create account' }}</flux:heading>
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" maxlength="100" required autocomplete="off" />
        <flux:select wire:model="type" label="Type" required>
            @foreach (AccountType::cases() as $accountType)
                <flux:select.option value="{{ $accountType->value }}">{{ $accountType->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="current_balance" :label="'Current balance ('.auth()->user()->currency.')'" inputmode="decimal" placeholder="0.00" required />
        <flux:text>Accounts are manually maintained current asset balances. Zero is valid.</flux:text>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save account</flux:button>
    </form>
</section>
