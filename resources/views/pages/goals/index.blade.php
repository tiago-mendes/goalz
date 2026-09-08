<?php

use App\Actions\RespondToGoalInvitation;
use App\GoalMembershipStatus;
use App\GoalStatus;
use App\Models\Goal;
use App\Models\GoalMembership;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Goals')] class extends Component {
    public function boot(): void
    {
        Gate::authorize('viewAny', Goal::class);
    }

    /** @return Collection<int, Goal> */
    #[Computed]
    public function ownedGoals(): Collection
    {
        return auth()->user()->goals()
            ->with('goalAccountAllocations:id,goal_id,amount')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByRaw('target_date IS NULL')
            ->orderBy('target_date')->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Goal> */
    #[Computed]
    public function personalGoals(): Collection
    {
        return Goal::query()->personalOwnedBy(auth()->user())
            ->with('goalAccountAllocations:id,goal_id,amount')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByRaw('target_date IS NULL')
            ->orderBy('target_date')->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Goal> */
    #[Computed]
    public function sharedGoals(): Collection
    {
        return Goal::query()->sharedFor(auth()->user())
            ->with(['goalAccountAllocations:id,goal_id,amount', 'owner:id,name'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, GoalMembership> */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return auth()->user()->sharedGoalMemberships()
            ->where('status', GoalMembershipStatus::Pending)
            ->with([
                'goal' => fn ($query) => $query->select(['id', 'user_id', 'name', 'target_amount', 'target_date']),
                'inviter:id,name',
            ])
            ->orderBy('created_at')->orderBy('id')->get();
    }

    #[Computed]
    public function activeGoals(): int
    {
        return $this->ownedGoals->where('status', GoalStatus::Active)->count();
    }

    #[Computed]
    public function totalTarget(): string
    {
        $total = BigDecimal::of('0.00');
        foreach ($this->ownedGoals->where('status', GoalStatus::Active) as $goal) {
            $total = $total->plus($goal->target_amount);
        }

        return (string) $total->toScale(2);
    }

    #[Computed]
    public function totalAllocated(): string
    {
        $total = BigDecimal::of('0.00');
        foreach ($this->ownedGoals->where('status', GoalStatus::Active) as $goal) {
            $total = $total->plus($goal->allocatedAmount());
        }

        return (string) $total->toScale(2);
    }

    #[Computed]
    public function totalProgressPercentage(): string
    {
        $target = BigDecimal::of($this->totalTarget);

        if ($target->isZero()) {
            return '0.0';
        }

        return (string) BigDecimal::of($this->totalAllocated)
            ->multipliedBy(100)
            ->dividedBy($target, 1, RoundingMode::HalfUp);
    }

    #[Computed]
    public function totalVisualProgressPercentage(): string
    {
        $progress = BigDecimal::of($this->totalProgressPercentage);

        return (string) ($progress->isGreaterThan(100) ? BigDecimal::of('100.0') : $progress)->toScale(1);
    }

    public function respondToInvitation(int $membershipId, string $response, RespondToGoalInvitation $respond): void
    {
        $validated = Validator::make(['response' => $response], [
            'response' => ['required', \Illuminate\Validation\Rule::in([
                GoalMembershipStatus::Accepted->value,
                GoalMembershipStatus::Declined->value,
            ])],
        ])->validate();

        $status = GoalMembershipStatus::from($validated['response']);
        $respond->handle(auth()->user(), $membershipId, $status);
        unset($this->pendingInvitations, $this->sharedGoals);
        session()->flash('status', $status === GoalMembershipStatus::Accepted ? 'Invitation accepted.' : 'Invitation declined.');
    }

    public function setStatus(int $goalId, string $status): void
    {
        $goal = auth()->user()->goals()->find($goalId);
        abort_if($goal === null, 404);
        Gate::authorize('update', $goal);
        $validated = Validator::make(['status' => $status], [
            'status' => ['required', 'string', \Illuminate\Validation\Rule::in(array_column(GoalStatus::cases(), 'value'))],
        ])->validate();
        $goal->status = GoalStatus::from($validated['status']);
        $goal->save();
        unset($this->ownedGoals, $this->personalGoals, $this->sharedGoals, $this->activeGoals, $this->totalTarget, $this->totalAllocated, $this->totalProgressPercentage, $this->totalVisualProgressPercentage);
        session()->flash('status', 'Goal status updated.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-4"><flux:heading size="xl" level="1">Goals</flux:heading><flux:button variant="primary" :href="route('goals.create')" wire:navigate>Create goal</flux:button></div>
    @if (session('status')) <flux:callout>{{ session('status') }}</flux:callout> @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-2 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700"><flux:heading size="lg">Active Goals</flux:heading><p class="text-2xl font-semibold tabular-nums">{{ $this->activeGoals }}</p></div>
        <div class="space-y-2 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700"><flux:heading size="lg">Total Target</flux:heading><p class="break-words text-2xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->totalTarget }}</p><flux:text class="text-sm tabular-nums">{{ auth()->user()->currency }} {{ $this->totalAllocated }} / {{ $this->totalTarget }} · {{ $this->totalProgressPercentage }}%</flux:text><div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="Total funding progress" aria-valuenow="{{ $this->totalVisualProgressPercentage }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-green-600" style="width: {{ $this->totalVisualProgressPercentage }}%"></div></div></div>
    </div>

    @if ($this->pendingInvitations->isNotEmpty())
        <section class="space-y-4" aria-labelledby="pending-invitations-heading">
            <div class="flex items-center gap-2"><flux:heading size="lg" level="2" id="pending-invitations-heading">Pending Invitations</flux:heading><flux:badge color="yellow">Pending</flux:badge></div>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($this->pendingInvitations as $invitation)
                    <article wire:key="goal-invitation-{{ $invitation->id }}" class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                        <div><flux:heading>{{ $invitation->goal->name }}</flux:heading><flux:text>Invited by {{ $invitation->inviter->name }}</flux:text></div>
                        <dl class="grid grid-cols-[auto_auto] gap-x-3 gap-y-1 text-sm tabular-nums"><dt>Target</dt><dd class="text-right">{{ auth()->user()->currency }} {{ $invitation->goal->target_amount }}</dd><dt>Target date</dt><dd class="text-right">{{ $invitation->goal->target_date?->format('M j, Y') ?? '—' }}</dd></dl>
                        <div class="flex gap-2"><flux:button size="sm" variant="primary" wire:click="respondToInvitation({{ $invitation->id }}, 'accepted')" wire:loading.attr="disabled">Accept</flux:button><flux:button size="sm" wire:click="respondToInvitation({{ $invitation->id }}, 'declined')" wire:loading.attr="disabled">Decline</flux:button></div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="space-y-4" aria-labelledby="my-goals-heading">
        <div class="flex items-center gap-2"><flux:heading size="lg" level="2" id="my-goals-heading">My Goals</flux:heading><flux:badge color="green">Owner</flux:badge></div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full min-w-[52rem] text-left text-sm"><caption class="sr-only">Goals you own</caption><thead class="bg-zinc-50 dark:bg-zinc-900"><tr><th scope="col" class="px-4 py-3">Name</th><th scope="col" class="px-4 py-3">Funding progress</th><th scope="col" class="px-4 py-3">Target Date</th><th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3">Actions</th></tr></thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->personalGoals as $goal)
                        <tr wire:key="owned-goal-{{ $goal->id }}"><td class="px-4 py-3 font-medium">{{ $goal->name }}</td><td class="min-w-64 px-4 py-3"><div class="flex justify-between gap-3 tabular-nums"><span>{{ auth()->user()->currency }} {{ $goal->allocatedAmount() }} / {{ $goal->target_amount }}</span><span>{{ $goal->progressPercentage() }}%</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="{{ $goal->name }} funding progress" aria-valuenow="{{ $goal->visualProgressPercentage() }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-green-600" style="width: {{ $goal->visualProgressPercentage() }}%"></div></div></td><td class="whitespace-nowrap px-4 py-3">{{ $goal->target_date?->format('M j, Y') ?? '—' }}</td><td class="px-4 py-3"><flux:badge :color="$goal->status === GoalStatus::Active ? 'green' : ($goal->status === GoalStatus::Paused ? 'yellow' : 'zinc')">{{ $goal->status->label() }}</flux:badge></td>
                            <td class="px-4 py-3"><div class="flex flex-wrap gap-2"><flux:button size="sm" :href="route('goals.allocations', $goal->id)" wire:navigate>Manage</flux:button><flux:button size="sm" :href="route('goals.edit', $goal->id)" wire:navigate>Edit</flux:button><flux:dropdown><flux:button size="sm">Change status</flux:button><flux:menu>@foreach (GoalStatus::cases() as $status)<flux:menu.item wire:click="setStatus({{ $goal->id }}, '{{ $status->value }}')" wire:confirm="Change this goal's status?">{{ $status->label() }}</flux:menu.item>@endforeach</flux:menu></flux:dropdown></div></td></tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6"><flux:text>No goals yet. Create your first goal to start planning what comes next.</flux:text></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="space-y-4" aria-labelledby="shared-goals-heading">
        <div class="flex items-center gap-2"><flux:heading size="lg" level="2" id="shared-goals-heading">Shared</flux:heading><flux:badge color="blue">Shared</flux:badge></div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full min-w-[52rem] text-left text-sm"><caption class="sr-only">Goals shared with accepted members</caption><thead class="bg-zinc-50 dark:bg-zinc-900"><tr><th scope="col" class="px-4 py-3">Goal</th><th scope="col" class="px-4 py-3">Owner</th><th scope="col" class="px-4 py-3">Funding progress</th><th scope="col" class="px-4 py-3">Target Date</th><th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3">Actions</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->sharedGoals as $goal)
                    <tr wire:key="shared-goal-{{ $goal->id }}"><td class="px-4 py-3 font-medium">{{ $goal->name }}</td><td class="px-4 py-3">{{ $goal->owner->name }}</td><td class="min-w-64 px-4 py-3"><div class="flex justify-between gap-3 tabular-nums"><span>{{ auth()->user()->currency }} {{ $goal->allocatedAmount() }} / {{ $goal->target_amount }}</span><span>{{ $goal->progressPercentage() }}%</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="{{ $goal->name }} funding progress" aria-valuenow="{{ $goal->visualProgressPercentage() }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-green-600" style="width: {{ $goal->visualProgressPercentage() }}%"></div></div></td><td class="whitespace-nowrap px-4 py-3">{{ $goal->target_date?->format('M j, Y') ?? '—' }}</td><td class="px-4 py-3"><flux:badge :color="$goal->status === GoalStatus::Active ? 'green' : ($goal->status === GoalStatus::Paused ? 'yellow' : 'zinc')">{{ $goal->status->label() }}</flux:badge></td><td class="px-4 py-3">
                        @if ($goal->isOwnedBy(auth()->user()))
                            <div class="flex flex-wrap gap-2"><flux:button size="sm" :href="route('goals.allocations', $goal->id)" wire:navigate>Manage</flux:button><flux:button size="sm" :href="route('goals.edit', $goal->id)" wire:navigate>Edit</flux:button><flux:dropdown><flux:button size="sm">Change status</flux:button><flux:menu>@foreach (GoalStatus::cases() as $status)<flux:menu.item wire:click="setStatus({{ $goal->id }}, '{{ $status->value }}')" wire:confirm="Change this goal's status?">{{ $status->label() }}</flux:menu.item>@endforeach</flux:menu></flux:dropdown></div>
                        @else
                            <flux:button size="sm" :href="route('goals.allocations', $goal->id)" wire:navigate>View goal</flux:button>
                        @endif
                    </td></tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6"><flux:text>No accepted shared goals yet.</flux:text></td></tr>
                @endforelse
            </tbody></table>
        </div>
    </section>
</section>
