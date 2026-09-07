<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Account balance history')] class extends Component {
    #[Locked]
    public int $accountId;

    public function boot(): void
    {
        Gate::authorize('viewAny', Account::class);
    }

    public function mount(int $accountId): void
    {
        $this->accountId = $accountId;
        Gate::authorize('view', $this->account);
    }

    #[Computed]
    public function account(): Account
    {
        $account = auth()->user()->accounts()->find($this->accountId);
        abort_if($account === null, 404);

        return $account;
    }

    /** @return Collection<int, AccountBalanceSnapshot> */
    #[Computed]
    public function snapshots(): Collection
    {
        return $this->account->balanceSnapshots()
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get();
    }
};
?>

<section class="mx-auto w-full max-w-5xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $this->account->name }}</flux:heading>
        <flux:button :href="route('accounts.index')" wire:navigate>Back to accounts</flux:button>
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">Account details</flux:heading>
            <flux:badge :color="$this->account->is_active ? 'green' : 'zinc'">{{ $this->account->is_active ? 'Active' : 'Inactive' }}</flux:badge>
        </div>
        <dl class="grid gap-2 sm:grid-cols-[auto_1fr] sm:gap-x-6">
            <dt>Current Balance</dt>
            <dd class="break-words text-xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->account->current_balance }}</dd>
            <dt>Balance Updated</dt>
            <dd>{{ $this->account->balance_updated_at->format('M j, Y H:i') }}</dd>
        </dl>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">Balance History</flux:heading>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Reported balance history for {{ $this->account->name }}</caption>
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th scope="col" class="px-4 py-3">Recorded</th>
                        <th scope="col" class="px-4 py-3">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->snapshots as $snapshot)
                        <tr wire:key="account-balance-snapshot-{{ $snapshot->id }}">
                            <td class="whitespace-nowrap px-4 py-3">{{ $snapshot->recorded_at->format('M j, Y H:i') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ auth()->user()->currency }} {{ $snapshot->balance }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="px-4 py-6"><flux:text>No balance history is available.</flux:text></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
