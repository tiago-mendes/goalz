<?php

use App\Actions\StopBudget;
use App\Models\BudgetRule;
use App\BudgetMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Budgets')] class extends Component {
    public function boot(): void
    {
        Gate::authorize('viewAny', BudgetRule::class);
    }

    #[Computed]
    public function budgets(): Collection
    {
        $current = CarbonImmutable::now()->startOfMonth();

        return auth()->user()->budgetRules()->with(['expenseCategory', 'months'])->orderBy('expense_category_id')->orderBy('starts_month')->orderBy('id')->get()
            ->filter(fn (BudgetRule $rule): bool => $rule->mode === BudgetMode::Recurring
                ? $rule->ends_month === null || ! $rule->ends_month->startOfMonth()->lessThan($current)
                : $rule->months->contains(fn ($month): bool => ! $month->month->startOfMonth()->lessThan($current)))->values();
    }

    public function stop(int $budgetId): void
    {
        $budget = auth()->user()->budgetRules()->with('months')->find($budgetId);
        abort_if($budget === null, 404);
        app(StopBudget::class)->handle($budget);
        unset($this->budgets);
        session()->flash('status', 'Budget stopped for future months.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-2"><flux:heading size="xl" level="1">Budgets</flux:heading><flux:text>Plan category spending without changing your expense history.</flux:text></div>
        <flux:button variant="primary" :href="route('budgets.create')" wire:navigate>Create budget</flux:button>
    </div>
    @if (session('status')) <flux:callout>{{ session('status') }}</flux:callout> @endif
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full min-w-[48rem] text-left text-sm"><caption class="sr-only">Current and future budgets</caption><thead class="bg-zinc-50 dark:bg-zinc-900"><tr><th class="px-4 py-3">Category</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3">Applies to</th><th class="px-4 py-3">Effective period</th><th class="px-4 py-3">Actions</th></tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->budgets as $budget)
                    <tr wire:key="budget-{{ $budget->id }}"><td class="px-4 py-3"><span class="inline-flex items-center gap-2"><span style="color: {{ $budget->expenseCategory->safeColor() }}" aria-hidden="true"><flux:icon :name="$budget->expenseCategory->safeIcon()" class="size-5" /></span>{{ $budget->expenseCategory->name }}</span></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$budget->amount" /></td><td class="px-4 py-3">{{ $budget->mode->label() }}</td><td class="px-4 py-3">@if ($budget->mode === BudgetMode::Recurring) {{ $budget->starts_month->format('M Y') }} onward @else {{ $budget->months->filter(fn ($month) => ! $month->month->lessThan(now()->startOfMonth()))->map(fn ($month) => $month->month->format('M Y'))->join(', ') }} @endif</td><td class="px-4 py-3"><div class="flex flex-wrap gap-2"><flux:button size="sm" :href="route('budgets.edit', $budget->id)" wire:navigate>Edit</flux:button><flux:button size="sm" wire:click="stop({{ $budget->id }})" wire:confirm="Stop this budget for future months?">Stop</flux:button></div></td></tr>
                @empty <tr><td colspan="5" class="px-4 py-8"><flux:text>No budgets yet. Create your first budget to get started.</flux:text></td></tr> @endforelse
            </tbody>
        </table>
    </div>
</section>
