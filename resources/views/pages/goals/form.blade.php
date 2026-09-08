<?php

use App\GoalStatus;
use App\Models\Goal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage goal')] class extends Component {
    #[Locked]
    public ?int $goalId = null;
    public string $name = '';
    public string $target_amount = '';
    public ?string $target_date = null;

    public function boot(): void
    {
        Gate::authorize('viewAny', Goal::class);
    }

    public function mount(?int $goalId = null): void
    {
        if ($goalId !== null) {
            $this->goalId = $goalId;
            $goal = $this->ownedGoal();
            Gate::authorize('view', $goal);
            $this->name = $goal->name;
            $this->target_amount = $goal->target_amount;
            $this->target_date = $goal->target_date?->toDateString();
        }
    }

    public function save(): void
    {
        $goal = $this->goalId === null ? auth()->user()->goals()->make() : $this->ownedGoal();
        Gate::authorize($goal->exists ? 'update' : 'create', $goal->exists ? $goal : Goal::class);
        $this->name = trim($this->name);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('goals', 'name')->where('user_id', auth()->id())->ignore($goal)],
            'target_amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/', 'not_in:0,0.0,0.00'],
            'target_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31'],
        ], [
            'target_amount.regex' => 'Enter a target up to 9999999999999.99 using at most two decimal places.',
            'target_amount.not_in' => 'The target amount must be greater than zero.',
        ]);

        $goal->fill($validated);
        if (! $goal->exists) {
            $goal->status = GoalStatus::Active;
        }

        try {
            $goal->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'The name has already been taken.']);
        }

        session()->flash('status', 'Goal saved.');
        $this->redirectRoute('goals.index', navigate: true);
    }

    private function ownedGoal(): Goal
    {
        $goal = auth()->user()->goals()->find($this->goalId);
        abort_if($goal === null, 404);

        return $goal;
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <flux:button :href="route('goals.index')" wire:navigate>Back to goals</flux:button>
    <flux:heading size="xl" level="1">{{ $goalId ? 'Edit goal' : 'Create goal' }}</flux:heading>
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" maxlength="100" required autocomplete="off" />
            <flux:input wire:model="target_amount" :label="'Target amount ('.\App\Support\CurrencyDisplay::symbol(auth()->user()->currency).')'" inputmode="decimal" placeholder="0.01" required />
        <flux:input wire:model="target_date" label="Target date" type="date" min="1000-01-01" max="9999-12-31" />
        <flux:text>Goals are plans. Allocating funds designates part of an account balance without moving money.</flux:text>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save goal</flux:button>
    </form>
</section>
