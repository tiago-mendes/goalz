<?php

use App\Models\CreditCard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage credit card')] class extends Component {
    #[Locked]
    public ?int $creditCardId = null;

    public string $name = '';
    public $cycle_start_day = '';
    public $due_day = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', CreditCard::class);
    }

    public function mount(?int $creditCardId = null): void
    {
        if ($creditCardId === null) {
            return;
        }

        $this->creditCardId = $creditCardId;
        $creditCard = $this->ownedCreditCard();
        Gate::authorize('view', $creditCard);
        $this->name = $creditCard->name;
        $this->cycle_start_day = $creditCard->cycle_start_day;
        $this->due_day = $creditCard->due_day;
    }

    public function save(): void
    {
        $creditCard = $this->creditCardId === null
            ? auth()->user()->creditCards()->make()
            : $this->ownedCreditCard();
        Gate::authorize($creditCard->exists ? 'update' : 'create', $creditCard->exists ? $creditCard : CreditCard::class);

        $this->name = trim($this->name);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('credit_cards', 'name')->where('user_id', auth()->id())->ignore($creditCard)],
            'cycle_start_day' => ['required', 'integer', 'between:1,31'],
            'due_day' => ['required', 'integer', 'between:1,31'],
        ]);

        $creditCard->fill($validated);
        if (! $creditCard->exists) {
            $creditCard->is_active = true;
        }

        try {
            $creditCard->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'The name has already been taken.']);
        }

        session()->flash('status', 'Credit card saved.');
        $this->redirectRoute('credit-cards.index', navigate: true);
    }

    private function ownedCreditCard(): CreditCard
    {
        $creditCard = auth()->user()->creditCards()->find($this->creditCardId);
        abort_if($creditCard === null, 404);

        return $creditCard;
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <flux:button :href="route('credit-cards.index')" wire:navigate>Back to credit cards</flux:button>
    <flux:heading size="xl" level="1">{{ $creditCardId ? 'Edit credit card' : 'Add credit card' }}</flux:heading>
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" maxlength="100" required autocomplete="off" />
        <flux:input wire:model="cycle_start_day" label="Cycle starts on day" type="number" min="1" max="31" step="1" required />
        <flux:text>A cycle ends the day before the next cycle starts. Days beyond a short month use its last day.</flux:text>
        <flux:input wire:model="due_day" label="Due day" type="number" min="1" max="31" step="1" required />
        <flux:text>The due date is the first occurrence of this day after the cycle ends, clamped in short months.</flux:text>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save credit card</flux:button>
    </form>
</section>
