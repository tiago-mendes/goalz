<?php

use App\Actions\SaveGoalAccountAllocation;
use App\Actions\EndGoalMembership;
use App\Actions\InviteGoalMember;
use App\GoalMembershipStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccountAllocation;
use App\Models\GoalMembership;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Goal allocations')] class extends Component {
    #[Locked]
    public int $goalId;

    #[Locked]
    public ?int $allocationId = null;

    public ?int $accountId = null;

    public string $amount = '';

    public string $percentage = '0.0';

    public bool $showEditor = false;

    public string $inviteEmail = '';

    public function mount(int $goalId): void
    {
        $this->goalId = $goalId;
        Gate::authorize('view', $this->accessibleGoal());
    }

    #[Computed]
    public function goal(): Goal
    {
        return $this->accessibleGoal()->load([
            'goalAccountAllocations:id,goal_id,amount',
            'owner:id,name',
        ]);
    }

    /** @return Collection<int, GoalAccountAllocation> */
    #[Computed]
    public function allocations(): Collection
    {
        return $this->accessibleGoal()->goalAccountAllocations()
            ->whereHas('account', fn ($query) => $query->where('user_id', auth()->id()))
            ->with(['account.goalAccountAllocations'])
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{userId: int, name: string, amount: string, isOwner: bool}> */
    #[Computed]
    public function contributions(): array
    {
        $goal = $this->accessibleGoal();
        $memberIds = $goal->memberships()
            ->where('status', GoalMembershipStatus::Accepted)
            ->pluck('user_id');
        $participantIds = $memberIds->prepend($goal->user_id)->unique()->values();
        $totals = GoalAccountAllocation::query()
            ->join('accounts', 'accounts.id', '=', 'goal_account_allocations.account_id')
            ->where('goal_account_allocations.goal_id', $goal->id)
            ->whereIn('accounts.user_id', $participantIds)
            ->selectRaw('accounts.user_id, CAST(SUM(goal_account_allocations.amount) AS CHAR) as contribution_total')
            ->groupBy('accounts.user_id')
            ->pluck('contribution_total', 'accounts.user_id');

        return User::query()->whereIn('id', $participantIds)->get(['id', 'name'])
            ->sortBy(fn (User $user): array => [$user->id === $goal->user_id ? 0 : 1, mb_strtolower($user->name), $user->id])
            ->map(fn (User $user): array => [
                'userId' => $user->id,
                'name' => $user->name,
                'amount' => (string) BigDecimal::of($totals->get($user->id, '0.00'))->toScale(2),
                'isOwner' => $user->id === $goal->user_id,
            ])->values()->all();
    }

    /** @return Collection<int, GoalMembership> */
    #[Computed]
    public function memberships(): Collection
    {
        if (! $this->accessibleGoal()->isOwnedBy(auth()->user())) {
            return new Collection;
        }

        return $this->accessibleGoal()->memberships()
            ->whereIn('status', [GoalMembershipStatus::Pending, GoalMembershipStatus::Accepted])
            ->with('user:id,name')
            ->orderBy('status')->orderBy('id')->get();
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function eligibleAccounts(): Collection
    {
        return auth()->user()->accounts()
            ->where('is_active', true)
            ->whereDoesntHave('goalAccountAllocations', fn ($query) => $query->where('goal_id', $this->goalId))
            ->with('goalAccountAllocations')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function startAdd(): void
    {
        $this->resetEditor();
        $this->showEditor = true;
    }

    public function editAllocation(int $allocationId): void
    {
        $allocation = $this->ownedAllocation($allocationId);
        $this->allocationId = $allocation->id;
        $this->accountId = $allocation->account_id;
        $this->amount = $allocation->amount;
        $this->percentage = $this->percentageFor($allocation->amount, $allocation->account->current_balance);
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function updatedAccountId(): void
    {
        if ($this->allocationId === null) {
            $this->amount = '';
            $this->percentage = '0.0';
        }
    }

    public function updatedAmount(): void
    {
        $account = $this->selectedOwnedAccount();

        if ($account === null || $this->amount === '') {
            $this->percentage = '0.0';

            return;
        }

        try {
            $this->percentage = $this->percentageFor($this->amount, $account->current_balance);
        } catch (NumberFormatException) {
            $this->percentage = '0.0';
        }
    }

    public function updatedPercentage(): void
    {
        $account = $this->selectedOwnedAccount();

        if ($account === null || BigDecimal::of($account->current_balance)->isZero()) {
            $this->amount = '0.00';
            $this->percentage = '0.0';

            return;
        }

        try {
            $this->amount = (string) BigDecimal::of($account->current_balance)
                ->multipliedBy(BigDecimal::of($this->percentage))
                ->dividedBy(100, 2, RoundingMode::HalfUp);
        } catch (NumberFormatException) {
            $this->amount = '';
            $this->percentage = '0.0';
        }
    }

    public function save(SaveGoalAccountAllocation $saveAllocation): void
    {
        $this->validate([
            'accountId' => ['required', 'integer'],
            'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/', 'not_in:0,0.0,0.00'],
        ], [
            'accountId.required' => 'Choose an account.',
            'amount.regex' => 'Enter an amount up to 9999999999999.99 using at most two decimal places.',
            'amount.not_in' => 'The amount must be greater than zero.',
        ]);

        $accountId = $this->allocationId === null
            ? $this->accountId
            : $this->ownedAllocation($this->allocationId)->account_id;

        $saveAllocation->handle(auth()->user(), $this->goalId, $accountId, $this->amount, $this->allocationId);

        $this->afterWrite('Allocation saved.');
    }

    public function invite(InviteGoalMember $invite): void
    {
        Gate::authorize('invite', $this->accessibleGoal());
        $this->inviteEmail = trim($this->inviteEmail);
        $this->validate(['inviteEmail' => ['required', 'email:rfc', 'max:255']]);
        $invite->handle(auth()->user(), $this->goalId, $this->inviteEmail);
        $this->inviteEmail = '';
        unset($this->memberships, $this->contributions);
        session()->flash('status', 'Invitation sent.');
    }

    public function removeMember(int $memberUserId, EndGoalMembership $endMembership): void
    {
        $goal = $this->accessibleGoal();
        Gate::authorize('removeMember', $goal);
        $endMembership->handle(auth()->user(), $goal->id, $memberUserId);
        unset($this->goal, $this->memberships, $this->contributions);
        session()->flash('status', 'Member removed.');
    }

    public function leaveGoal(EndGoalMembership $endMembership): void
    {
        $goal = $this->accessibleGoal();
        Gate::authorize('leave', $goal);
        $endMembership->handle(auth()->user(), $goal->id, auth()->id());
        session()->flash('status', 'You left the shared goal.');
        $this->redirectRoute('goals.index', navigate: true);
    }

    public function removeAllocation(int $allocationId): void
    {
        $allocation = $this->ownedAllocation($allocationId);

        DB::transaction(function () use ($allocation): void {
            $account = Account::query()->whereKey($allocation->account_id)->whereBelongsTo(auth()->user())->lockForUpdate()->first();
            abort_if($account === null, 404);
            $goal = Goal::query()->whereKey($this->goalId)->accessibleTo(auth()->user())->lockForUpdate()->first();
            abort_if($goal === null, 404);
            $lockedAllocation = GoalAccountAllocation::query()->whereKey($allocation->id)
                ->whereBelongsTo($goal)->whereBelongsTo($account)->lockForUpdate()->first();
            abort_if($lockedAllocation === null, 404);
            $lockedAllocation->delete();
        }, attempts: 3);

        $this->afterWrite('Allocation removed.');
    }

    #[Computed]
    public function maximumAmount(): string
    {
        $account = $this->selectedOwnedAccount();

        if ($account === null) {
            return '0.00';
        }

        $current = $this->allocationId === null ? BigDecimal::of('0.00') : BigDecimal::of($this->ownedAllocation($this->allocationId)->amount);

        if (! $account->is_active) {
            return (string) $current->toScale(2);
        }

        $accountMaximum = BigDecimal::of($account->current_balance)->minus($this->sumOtherAccountAllocations($account));
        $goalMaximum = BigDecimal::of($this->goal->target_amount)->minus($this->sumOtherGoalAllocations());
        $maximum = $accountMaximum->isLessThan($goalMaximum) ? $accountMaximum : $goalMaximum;

        if ($maximum->isLessThan($current)) {
            $maximum = $current;
        }

        return $maximum->isNegative() ? '0.00' : (string) $maximum->toScale(2);
    }

    #[Computed]
    public function maximumPercentage(): string
    {
        $account = $this->selectedOwnedAccount();

        if ($account === null || BigDecimal::of($account->current_balance)->isZero()) {
            return '0.0';
        }

        return $this->percentageFor($this->maximumAmount, $account->current_balance);
    }

    private function accessibleGoal(): Goal
    {
        $goal = Goal::query()->accessibleTo(auth()->user())->find($this->goalId);
        abort_if($goal === null, 404);

        return $goal;
    }

    private function ownedAllocation(int $allocationId): GoalAccountAllocation
    {
        $allocation = $this->accessibleGoal()->goalAccountAllocations()
            ->whereKey($allocationId)
            ->whereHas('account', fn ($query) => $query->where('user_id', auth()->id()))
            ->with('account')
            ->first();
        abort_if($allocation === null, 404);

        return $allocation;
    }

    public function selectedOwnedAccount(): ?Account
    {
        if ($this->accountId === null) {
            return null;
        }

        return auth()->user()->accounts()->with('goalAccountAllocations')->find($this->accountId);
    }

    public function percentageFor(string $amount, string $balance): string
    {
        if (BigDecimal::of($balance)->isZero()) {
            return '0.0';
        }

        return (string) BigDecimal::of($amount)->multipliedBy(100)->dividedBy($balance, 1, RoundingMode::HalfUp);
    }

    private function sumOtherAccountAllocations(Account $account): BigDecimal
    {
        $total = BigDecimal::of('0.00');

        foreach ($account->goalAccountAllocations as $allocation) {
            if ($allocation->id !== $this->allocationId) {
                $total = $total->plus($allocation->amount);
            }
        }

        return $total;
    }

    private function sumOtherGoalAllocations(): BigDecimal
    {
        $total = BigDecimal::of('0.00');

        foreach ($this->goal->goalAccountAllocations as $allocation) {
            if ($allocation->id !== $this->allocationId) {
                $total = $total->plus($allocation->amount);
            }
        }

        return $total;
    }

    private function afterWrite(string $message): void
    {
        $this->resetEditor();
        $this->showEditor = false;
        unset($this->goal, $this->allocations, $this->eligibleAccounts, $this->contributions, $this->maximumAmount, $this->maximumPercentage);
        session()->flash('status', $message);
    }

    private function resetEditor(): void
    {
        $this->allocationId = null;
        $this->accountId = null;
        $this->amount = '';
        $this->percentage = '0.0';
        $this->resetValidation();
        unset($this->maximumAmount, $this->maximumPercentage);
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-2">
            <flux:button :href="route('goals.index')" wire:navigate>Back to goals</flux:button>
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">{{ $this->goal->name }}</flux:heading>
                <flux:badge :color="$this->goal->isOwnedBy(auth()->user()) ? 'green' : 'blue'">{{ $this->goal->isOwnedBy(auth()->user()) ? 'Owner' : 'Shared' }}</flux:badge>
            </div>
            <flux:text>Owned by {{ $this->goal->owner->name }}</flux:text>
            <flux:text>Designate funds from your accounts. Account balances never change here.</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($this->eligibleAccounts->isNotEmpty())
                <flux:button variant="primary" wire:click="startAdd">Add allocation</flux:button>
            @endif
            @if (! $this->goal->isOwnedBy(auth()->user()))
                <flux:button variant="danger" wire:click="leaveGoal" wire:confirm="Leaving this shared goal will remove your current allocations from it." wire:loading.attr="disabled">Leave goal</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="space-y-2 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700"><flux:heading>Target</flux:heading><p class="text-xl font-semibold tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$this->goal->target_amount" /></p></div>
        <div class="space-y-2 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700"><flux:heading>Allocated</flux:heading><p class="text-xl font-semibold tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$this->goal->allocatedAmount()" /></p></div>
        <div class="space-y-2 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>{{ BigDecimal::of($this->goal->remainingAmount())->isNegative() ? 'Overfunded' : 'Remaining' }}</flux:heading>
            <p class="text-xl font-semibold tabular-nums"><x-money :currency="auth()->user()->currency" :amount="BigDecimal::of($this->goal->remainingAmount())->isNegative() ? $this->goal->overfundedAmount() : $this->goal->remainingAmount()" /></p>
        </div>
        <div class="space-y-2 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700"><flux:heading>Progress</flux:heading><p class="text-xl font-semibold tabular-nums">{{ $this->goal->progressPercentage() }}%</p></div>
    </div>

    @if (BigDecimal::of($this->goal->remainingAmount())->isNegative())
            <flux:callout>The goal is overfunded by <x-money :currency="auth()->user()->currency" :amount="$this->goal->overfundedAmount()" />. Existing allocations are preserved; reduce or remove them if desired.</flux:callout>
    @endif

    <section class="space-y-4" aria-labelledby="goal-members-heading">
        <flux:heading size="lg" level="2" id="goal-members-heading">Members</flux:heading>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-left text-sm"><caption class="sr-only">Goal member contribution totals</caption><thead class="bg-zinc-50 dark:bg-zinc-900"><tr><th scope="col" class="px-4 py-3">Member</th><th scope="col" class="px-4 py-3 text-right">Contribution</th>@if ($this->goal->isOwnedBy(auth()->user()))<th scope="col" class="px-4 py-3">Actions</th>@endif</tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->contributions as $contribution)
                    <tr wire:key="goal-contribution-{{ $contribution['userId'] }}"><th scope="row" class="px-4 py-3 font-normal"><span class="inline-flex items-center gap-2">{{ $contribution['name'] }} @if ($contribution['isOwner'])<flux:badge color="green">Owner</flux:badge>@endif</span></th><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$contribution['amount']" /></td>@if ($this->goal->isOwnedBy(auth()->user()))<td class="px-4 py-3">@if (! $contribution['isOwner'])<flux:button size="sm" variant="danger" wire:click="removeMember({{ $contribution['userId'] }})" wire:confirm="Removing this member will also remove their current allocations from this goal." wire:loading.attr="disabled">Remove member</flux:button>@endif</td>@endif</tr>
                @endforeach
            </tbody></table>
        </div>

        @if ($this->goal->isOwnedBy(auth()->user()))
            <form wire:submit="invite" class="grid gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <flux:input wire:model="inviteEmail" label="Invite a registered user by email" type="email" maxlength="255" required />
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Send invitation</flux:button>
            </form>
            @if ($this->memberships->isNotEmpty())
                <div class="flex flex-wrap gap-2" aria-label="Current invitations and memberships">
                    @foreach ($this->memberships as $membership)
                        <flux:badge wire:key="goal-membership-{{ $membership->id }}" :color="$membership->status === GoalMembershipStatus::Accepted ? 'green' : 'yellow'">{{ $membership->user->name }} · {{ ucfirst($membership->status->value) }}</flux:badge>
                    @endforeach
                </div>
            @endif
        @endif
    </section>

    <div class="space-y-4">
        <flux:heading size="lg" level="2">Your Account Allocations</flux:heading>
        <div class="grid gap-4 md:grid-cols-2">
            @forelse ($this->allocations as $allocation)
                <article wire:key="allocation-{{ $allocation->id }}" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <div class="flex items-start justify-between gap-4">
                        <div><flux:heading>{{ $allocation->account->name }}</flux:heading><flux:text>{{ $allocation->account->type->label() }} · {{ $allocation->account->is_active ? 'Active' : 'Inactive' }}</flux:text></div>
                        <flux:badge :color="$allocation->account->is_active ? 'green' : 'zinc'">{{ $allocation->account->is_active ? 'Active' : 'Inactive' }}</flux:badge>
                    </div>
                    <div class="space-y-1 tabular-nums">
                        <p class="text-xl font-semibold"><x-money :currency="auth()->user()->currency" :amount="$allocation->amount" /></p>
                        <flux:text>{{ $this->percentageFor($allocation->amount, $allocation->account->current_balance) }}% of current account balance</flux:text>
                    </div>
                    @if (BigDecimal::of($allocation->account->availableAmount())->isNegative())
                            <flux:callout>Account is overallocated by <x-money :currency="auth()->user()->currency" :amount="$allocation->account->overallocatedAmount()" />. You may reduce or remove this allocation.</flux:callout>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" wire:click="editAllocation({{ $allocation->id }})">Edit amount</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="removeAllocation({{ $allocation->id }})" wire:confirm="Remove this allocation permanently?" wire:loading.attr="disabled">Remove allocation</flux:button>
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-6 dark:border-zinc-700"><flux:text>No funds allocated yet.</flux:text></div>
            @endforelse
        </div>
    </div>

    <flux:modal wire:model="showEditor" class="md:w-150">
        <form wire:submit="save" class="space-y-6">
            <div><flux:heading size="lg">{{ $allocationId ? 'Edit allocation' : 'Add allocation' }}</flux:heading><flux:text>Amount is the only value stored. Percentage is calculated from the account's current balance.</flux:text></div>

            @if ($allocationId)
                @php($editingAccount = $this->selectedOwnedAccount())
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800"><flux:heading>{{ $editingAccount?->name }}</flux:heading><flux:text>Account cannot be changed while editing.</flux:text></div>
            @else
                <flux:select wire:model.live="accountId" label="Account" required>
                    <flux:select.option value="">Choose an account</flux:select.option>
                    @foreach ($this->eligibleAccounts as $account)
                        <flux:select.option wire:key="eligible-account-{{ $account->id }}" value="{{ $account->id }}">{{ $account->name }} · {{ $account->type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="space-y-2" aria-label="Eligible account balances">
                    @foreach ($this->eligibleAccounts as $account)
                        <div wire:key="eligible-account-summary-{{ $account->id }}" class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                            <div class="flex flex-wrap items-center justify-between gap-2"><span class="font-medium">{{ $account->name }}</span><span>{{ $account->type->label() }}</span></div>
                            <dl class="mt-2 grid grid-cols-[auto_auto] gap-x-3 gap-y-1 tabular-nums">
                                <dt>Balance</dt><dd class="text-right"><x-money :currency="auth()->user()->currency" :amount="$account->current_balance" /></dd>
                                <dt>Allocated elsewhere</dt><dd class="text-right"><x-money :currency="auth()->user()->currency" :amount="$account->allocatedAmount()" /></dd>
                                <dt>Available</dt><dd class="text-right"><x-money :currency="auth()->user()->currency" :amount="$account->availableAmount()" /></dd>
                            </dl>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($selectedAccount = $this->selectedOwnedAccount())
                <dl class="grid grid-cols-[auto_auto] gap-x-4 gap-y-1 rounded-lg border border-zinc-200 p-4 text-sm tabular-nums dark:border-zinc-700">
                    <dt>Current balance</dt><dd class="text-right"><x-money :currency="auth()->user()->currency" :amount="$selectedAccount->current_balance" /></dd>
                    <dt>Already allocated</dt><dd class="text-right"><x-money :currency="auth()->user()->currency" :amount="$selectedAccount->allocatedAmount()" /></dd>
                    <dt>Available</dt><dd class="text-right"><x-money :currency="auth()->user()->currency" :amount="$selectedAccount->availableAmount()" /></dd>
                    <dt>Maximum for this goal</dt><dd class="text-right font-medium"><x-money :currency="auth()->user()->currency" :amount="$this->maximumAmount" /></dd>
                </dl>

                <flux:input wire:model.live.debounce.250ms="amount" :label="'Amount ('.\App\Support\CurrencyDisplay::symbol(auth()->user()->currency).')'" inputmode="decimal" placeholder="0.01" required />

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-4"><label for="allocation-percentage" class="text-sm font-medium">Percentage of current account balance</label><output for="allocation-percentage" class="tabular-nums">{{ $percentage }}%</output></div>
                    <input id="allocation-percentage" type="range" min="0" max="{{ $this->maximumPercentage }}" step="0.1" wire:model.live="percentage" class="w-full accent-green-700 disabled:opacity-50" @disabled(BigDecimal::of($selectedAccount->current_balance)->isZero()) />
                    <flux:text>Use the slider or enter the exact amount. The saved allocation remains fixed if the account balance changes later.</flux:text>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" :disabled="$accountId === null">Save allocation</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
