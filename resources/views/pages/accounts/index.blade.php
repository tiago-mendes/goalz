<?php

use App\Models\Account;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Accounts')] class extends Component {
    public function boot(): void
    {
        Gate::authorize('viewAny', Account::class);
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return auth()->user()->accounts()
            ->with('goalAccountAllocations')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function totalAssets(): string
    {
        $total = BigDecimal::of('0.00');

        foreach ($this->accounts->where('is_active', true) as $account) {
            $total = $total->plus($account->current_balance);
        }

        return (string) $total->toScale(2);
    }

    public function setActive(int $accountId, bool $active): void
    {
        $account = auth()->user()->accounts()->find($accountId);
        abort_if($account === null, 404);
        Gate::authorize('update', $account);
        $account->is_active = $active;
        $account->save();
        unset($this->accounts, $this->totalAssets);
        session()->flash('status', $active ? 'Account activated.' : 'Account deactivated.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Accounts</flux:heading>
        <flux:button variant="primary" :href="route('accounts.create')" wire:navigate>Create account</flux:button>
    </div>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif
    <div class="space-y-2 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <flux:heading size="lg">Total Assets</flux:heading>
        <flux:text>Across your active accounts.</flux:text>
        <p class="break-words text-2xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->totalAssets }}</p>
    </div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your accounts</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                <th scope="col" class="px-4 py-3">Name</th><th scope="col" class="px-4 py-3">Type</th>
                <th scope="col" class="px-4 py-3">Funds</th><th scope="col" class="px-4 py-3">Balance Updated</th>
                <th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->accounts as $account)
                    <tr wire:key="account-{{ $account->id }}">
                        <td class="px-4 py-3">{{ $account->name }}</td>
                        <td class="px-4 py-3">{{ $account->type->label() }}</td>
                        <td class="px-4 py-3">
                            <dl class="grid grid-cols-[auto_auto] gap-x-3 gap-y-1 tabular-nums">
                                <dt>Current Balance</dt><dd class="text-right">{{ auth()->user()->currency }} {{ $account->current_balance }}</dd>
                                <dt>Allocated</dt><dd class="text-right">{{ auth()->user()->currency }} {{ $account->allocatedAmount() }}</dd>
                                @if (BigDecimal::of($account->availableAmount())->isNegative())
                                    <dt class="font-medium text-red-600 dark:text-red-400">Overallocated</dt><dd class="text-right font-medium text-red-600 dark:text-red-400">{{ auth()->user()->currency }} {{ $account->overallocatedAmount() }}</dd>
                                @else
                                    <dt>Available</dt><dd class="text-right">{{ auth()->user()->currency }} {{ $account->availableAmount() }}</dd>
                                @endif
                            </dl>
                        </td>
                        <td class="px-4 py-3">{{ $account->balance_updated_at->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3"><flux:badge :color="$account->is_active ? 'green' : 'zinc'">{{ $account->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                        <td class="px-4 py-3"><div class="flex flex-wrap gap-2">
                            <flux:button size="sm" :href="route('accounts.edit', $account->id)" :aria-label="'Edit '.$account->name" wire:navigate>Edit</flux:button>
                            <flux:button size="sm" wire:click="setActive({{ $account->id }}, {{ $account->is_active ? 'false' : 'true' }})" wire:confirm="Change this account's active status?" wire:loading.attr="disabled">{{ $account->is_active ? 'Deactivate' : 'Activate' }}</flux:button>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6"><flux:text>No accounts yet. Add your first account to start tracking your assets.</flux:text></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
