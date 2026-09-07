<?php

use App\GoalStatus;
use App\Models\Goal;
use Brick\Math\BigDecimal;
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
    public function goals(): Collection
    {
        return auth()->user()->goals()
            ->with('goalAccountAllocations')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByRaw('target_date IS NULL')
            ->orderBy('target_date')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function activeGoals(): int
    {
        return $this->goals->where('status', GoalStatus::Active)->count();
    }

    #[Computed]
    public function totalTarget(): string
    {
        $total = BigDecimal::of('0.00');
        foreach ($this->goals->where('status', GoalStatus::Active) as $goal) {
            $total = $total->plus($goal->target_amount);
        }

        return (string) $total->toScale(2);
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
        unset($this->goals, $this->activeGoals, $this->totalTarget);
        session()->flash('status', 'Goal status updated.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Goals</flux:heading>
        <flux:button variant="primary" :href="route('goals.create')" wire:navigate>Create goal</flux:button>
    </div>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-2 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
            <flux:heading size="lg">Active Goals</flux:heading>
            <p class="text-2xl font-semibold tabular-nums">{{ $this->activeGoals }}</p>
        </div>
        <div class="space-y-2 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
            <flux:heading size="lg">Total Target</flux:heading>
            <p class="break-words text-2xl font-semibold tabular-nums">{{ auth()->user()->currency }} {{ $this->totalTarget }}</p>
        </div>
    </div>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your goals</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900"><tr>
                <th scope="col" class="px-4 py-3">Name</th><th scope="col" class="px-4 py-3">Funding progress</th>
                <th scope="col" class="px-4 py-3">Target Date</th><th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->goals as $goal)
                    <tr wire:key="goal-{{ $goal->id }}">
                        <td class="px-4 py-3">{{ $goal->name }}</td>
                        <td class="min-w-64 px-4 py-3">
                            <dl class="grid grid-cols-[auto_auto] gap-x-3 gap-y-1 tabular-nums">
                                <dt>Target</dt><dd class="text-right">{{ auth()->user()->currency }} {{ $goal->target_amount }}</dd>
                                <dt>Allocated</dt><dd class="text-right">{{ auth()->user()->currency }} {{ $goal->allocatedAmount() }}</dd>
                                @if (BigDecimal::of($goal->remainingAmount())->isNegative())
                                    <dt class="font-medium text-green-700 dark:text-green-400">Overfunded</dt><dd class="text-right font-medium text-green-700 dark:text-green-400">{{ auth()->user()->currency }} {{ $goal->overfundedAmount() }}</dd>
                                @else
                                    <dt>Remaining</dt><dd class="text-right">{{ auth()->user()->currency }} {{ $goal->remainingAmount() }}</dd>
                                @endif
                                <dt>Progress</dt><dd class="text-right">{{ $goal->progressPercentage() }}%</dd>
                            </dl>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="{{ $goal->name }} funding progress" aria-valuenow="{{ $goal->visualProgressPercentage() }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-green-600" style="width: {{ $goal->visualProgressPercentage() }}%"></div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $goal->target_date?->format('M j, Y') ?? '—' }}</td>
                        <td class="px-4 py-3"><flux:badge :color="$goal->status === GoalStatus::Active ? 'green' : ($goal->status === GoalStatus::Paused ? 'yellow' : 'zinc')">{{ $goal->status->label() }}</flux:badge></td>
                        <td class="px-4 py-3"><div class="flex flex-wrap gap-2">
                            <flux:button size="sm" :href="route('goals.allocations', $goal->id)" :aria-label="'Manage allocations for '.$goal->name" wire:navigate>Manage allocations</flux:button>
                            <flux:button size="sm" :href="route('goals.edit', $goal->id)" :aria-label="'Edit '.$goal->name" wire:navigate>Edit</flux:button>
                            <flux:dropdown>
                                <flux:button size="sm">Change status</flux:button>
                                <flux:menu>
                                    @foreach (GoalStatus::cases() as $status)
                                        <flux:menu.item wire:click="setStatus({{ $goal->id }}, '{{ $status->value }}')" wire:confirm="Change this goal's status?">{{ $status->label() }}</flux:menu.item>
                                    @endforeach
                                </flux:menu>
                            </flux:dropdown>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6"><flux:text>No goals yet. Create your first goal to start planning what comes next.</flux:text><div class="mt-4"><flux:button variant="primary" :href="route('goals.create')" wire:navigate>Create goal</flux:button></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
