<?php

use App\Models\FixedExpense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Fixed Expenses')] class extends Component {
    use WithPagination;

    public function boot(): void
    {
        Gate::authorize('viewAny', FixedExpense::class);
    }

    #[Computed]
    public function fixedExpenses(): LengthAwarePaginator
    {
        return auth()->user()->fixedExpenses()
            ->with(['expenseCategory' => fn (BelongsTo $query): BelongsTo => $query->where('user_id', auth()->id())])
            ->orderBy('name')->orderBy('id')->paginate(20);
    }

    public function setActive(int $fixedExpenseId, bool $active): void
    {
        $expense = auth()->user()->fixedExpenses()->find($fixedExpenseId);
        abort_if($expense === null, 404);
        Gate::authorize('update', $expense);
        $expense->is_active = $active;
        $expense->save();
        unset($this->fixedExpenses);
        session()->flash('status', $active ? 'Fixed expense activated.' : 'Fixed expense deactivated.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Fixed Expenses</flux:heading>
        <flux:button variant="primary" :href="route('fixed-expenses.create')" wire:navigate>Create fixed expense</flux:button>
    </div>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your recurring expense definitions</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Category</th>
                    <th scope="col" class="px-4 py-3">Amount</th>
                    <th scope="col" class="px-4 py-3">Day</th>
                    <th scope="col" class="px-4 py-3">Start date</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->fixedExpenses as $expense)
                    <tr wire:key="fixed-expense-{{ $expense->id }}">
                        <td class="px-4 py-3">{{ $expense->name }}</td>
                        <td class="px-4 py-3">{{ $expense->expenseCategory?->name ?? 'Category unavailable' }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ auth()->user()->currency }} {{ $expense->amount }}</td>
                        <td class="px-4 py-3">{{ $expense->day_of_month }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $expense->start_date->toDateString() }}</td>
                        <td class="px-4 py-3"><flux:badge :color="$expense->is_active ? 'green' : 'zinc'">{{ $expense->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <flux:button size="sm" :href="route('fixed-expenses.edit', $expense->id)" :aria-label="'Edit '.$expense->name" wire:navigate>Edit</flux:button>
                                <flux:button size="sm" wire:click="setActive({{ $expense->id }}, {{ $expense->is_active ? 'false' : 'true' }})" wire:confirm="Change this fixed expense's active status?" wire:loading.attr="disabled" :aria-label="($expense->is_active ? 'Deactivate ' : 'Activate ').$expense->name">{{ $expense->is_active ? 'Deactivate' : 'Activate' }}</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6"><flux:text>No fixed expenses yet. Create your first recurring definition to get started.</flux:text></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->fixedExpenses->links() }}
</section>
