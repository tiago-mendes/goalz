<?php

use App\Actions\SaveBudget;
use App\BudgetMode;
use App\Models\BudgetRule;
use App\Models\ExpenseCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Budget')] class extends Component {
    #[Locked] public ?int $budgetId = null;
    public ?int $expense_category_id = null;
    public string $amount = '';
    public string $mode = 'recurring';
    public string $startsMonth = '';
    /** @var list<string> */
    public array $selectedMonths = [];

    public function boot(): void { Gate::authorize('viewAny', BudgetRule::class); }

    public function mount(?int $budgetId = null): void
    {
        $this->startsMonth = now()->format('Y-m');
        if ($budgetId === null) return;
        $budget = auth()->user()->budgetRules()->with('months')->find($budgetId);
        abort_if($budget === null, 404);
        $this->budgetId = $budget->id; $this->expense_category_id = $budget->expense_category_id; $this->amount = (string) $budget->amount; $this->mode = $budget->mode->value;
        $this->startsMonth = $budget->starts_month->lessThan(now()->startOfMonth()) ? now()->format('Y-m') : $budget->starts_month->format('Y-m');
        $this->selectedMonths = $budget->months->map(fn ($month): string => $month->month->format('Y-m'))->all();
    }

    #[Computed]
    public function categories(): Collection { return auth()->user()->expenseCategories()->where(fn ($query) => $query->where('is_active', true)->orWhereKey($this->expense_category_id))->orderBy('name')->orderBy('id')->get(); }

    /** @return list<string> */
    #[Computed]
    public function monthOptions(): array
    {
        $options = []; $month = CarbonImmutable::now()->startOfMonth();
        for ($i = 0; $i < 24; $i++) { $options[] = $month->format('Y-m'); $month = $month->addMonth(); }
        return $options;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'expense_category_id' => ['required', 'integer'], 'amount' => ['required', 'string'],
            'mode' => ['required', 'in:recurring,selected_months'], 'startsMonth' => ['required', 'date_format:Y-m', 'after_or_equal:'.now()->format('Y-m')],
            'selectedMonths' => ['array'], 'selectedMonths.*' => ['date_format:Y-m'],
        ]);
        $categoryQuery = auth()->user()->expenseCategories()->whereKey($validated['expense_category_id']);
        if ($this->budgetId === null) {
            $categoryQuery->where('is_active', true);
        }
        $category = $categoryQuery->first();
        abort_if($category === null, 404);
        $existing = $this->budgetId === null ? null : auth()->user()->budgetRules()->find($this->budgetId);
        abort_if($this->budgetId !== null && $existing === null, 404);
        app(SaveBudget::class)->handle(auth()->user(), $category, $validated['amount'], BudgetMode::from($validated['mode']), $validated['startsMonth'], $validated['selectedMonths'], $existing);
        session()->flash('status', 'Budget saved.'); $this->redirectRoute('budgets.index', navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6"><flux:button :href="route('budgets.index')" wire:navigate>Back to budgets</flux:button><flux:heading size="xl" level="1">{{ $budgetId ? 'Edit budget' : 'Create budget' }}</flux:heading><flux:text>Past months stay unchanged. Changes apply from the current month or later.</flux:text>
    <form wire:submit="save" class="space-y-6"><x-category-select :categories="$this->categories" model="expense_category_id" :selected-id="$expense_category_id" label="Category" placeholder="Choose a category" :full-width="true" required /><flux:input wire:model="amount" label="Amount" inputmode="decimal" placeholder="1500.00" required /><flux:radio.group wire:model.live="mode" label="Applies to"><flux:radio value="recurring" label="All months" /><flux:radio value="selected_months" label="Selected months" /></flux:radio.group>@if ($mode === 'recurring')<flux:input wire:model="startsMonth" label="Effective from" type="month" min="1000-01" max="9999-12" required />@else<flux:fieldset><flux:legend>Months</flux:legend><div class="grid grid-cols-2 gap-3 sm:grid-cols-3">@foreach ($this->monthOptions as $month)<flux:checkbox wire:model="selectedMonths" value="{{ $month }}" label="{{ CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y') }}" wire:key="month-{{ $month }}" />@endforeach</div><flux:error name="selectedMonths" /></flux:fieldset>@endif<flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save budget</flux:button></form>
</section>
