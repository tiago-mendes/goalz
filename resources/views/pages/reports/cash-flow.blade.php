<?php

use App\Reports\CashFlowReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cash Flow Reports')] class extends Component {
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $categoryId = null;

    #[Locked]
    public string $selectedFrom = '';

    #[Locked]
    public string $selectedTo = '';

    public function mount(): void
    {
        $this->from = $this->from !== '' ? $this->from : now()->subMonths(5)->format('Y-m');
        $this->to = $this->to !== '' ? $this->to : now()->format('Y-m');
        $this->selectedFrom = $this->from;
        $this->selectedTo = $this->to;
        $this->validatePeriod();
        $this->selectDefaultCategory();
    }

    public function applyPeriod(): void
    {
        $this->validatePeriod();
        $this->selectedFrom = $this->from;
        $this->selectedTo = $this->to;
        unset($this->report, $this->chartData);
        $this->dispatch('cash-flow-chart-data-updated', data: $this->chartData);
    }

    public function updatedCategoryId(): void
    {
        $this->validate(['categoryId' => ['nullable', 'integer']]);
        if ($this->categoryId !== null && ! auth()->user()->expenseCategories()->whereKey($this->categoryId)->exists()) {
            $this->addError('categoryId', 'The selected category is unavailable.');
            $this->categoryId = null;
        }
        if ($this->categoryId === null) {
            $this->categoryId = $this->categories->first()?->id;
        }
        unset($this->report, $this->chartData);
        $this->dispatch('cash-flow-chart-data-updated', data: $this->chartData);
    }

    #[Computed]
    public function report(): array
    {
        return app(CashFlowReport::class)->handle(
            auth()->user(),
            CarbonImmutable::createFromFormat('!Y-m', $this->selectedFrom),
            CarbonImmutable::createFromFormat('!Y-m', $this->selectedTo),
            $this->categoryId,
        );
    }

    #[Computed]
    public function chartData(): array
    {
        $report = $this->report;
        $numeric = fn (?string $value): ?float => $value === null ? null : (float) $value;

        return [
            'monthly' => [
                'labels' => array_column($report['months'], 'label'),
                'income' => array_map($numeric, array_column($report['months'], 'income')),
                'expenses' => array_map($numeric, array_column($report['months'], 'expenses')),
            ],
            'categories' => [
                'labels' => array_column($report['categories'], 'name'),
                'amounts' => array_map($numeric, array_column($report['categories'], 'amount')),
                'colors' => array_column($report['categories'], 'color'),
            ],
            'sources' => [
                'labels' => array_column($report['months'], 'label'),
                'recurring' => array_map($numeric, array_column($report['months'], 'recurring')),
                'manual' => array_map($numeric, array_column($report['months'], 'manual')),
            ],
            'evolution' => [
                'labels' => array_column($report['categoryEvolution'], 'label'),
                'amounts' => array_map($numeric, array_column($report['categoryEvolution'], 'amount')),
            ],
        ];
    }

    #[Computed]
    public function categories(): \Illuminate\Database\Eloquent\Collection
    {
        return auth()->user()->expenseCategories()->orderBy('name')->orderBy('id')->get();
    }

    private function validatePeriod(): void
    {
        $validated = $this->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to' => ['required', 'date_format:Y-m'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m', $validated['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m', $validated['to']);
        Validator::make([], [])->after(function ($validator) use ($from, $to): void {
            if ($from->greaterThan($to)) {
                $validator->errors()->add('to', 'The end month must be on or after the start month.');
            }
            if ($from->diffInMonths($to) >= 60) {
                $validator->errors()->add('to', 'The reporting period cannot exceed 60 months.');
            }
        })->validate();
    }

    private function selectDefaultCategory(): void
    {
        if ($this->categoryId !== null && auth()->user()->expenseCategories()->whereKey($this->categoryId)->exists()) {
            return;
        }

        if ($this->categoryId !== null) {
            $this->addError('categoryId', 'The selected category is unavailable.');
            $this->categoryId = null;
        }

        $this->categoryId = $this->categories->first()?->id;
    }
}; ?>

<section data-cash-flow-charts x-data="cashFlowCharts(@js($this->chartData))" @cash-flow-chart-data-updated.window="render($event.detail.data)" class="mx-auto w-full max-w-7xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Cash Flow Reports</flux:heading>
            <flux:text>Analyze your persisted income and expense history.</flux:text>
        </div>
        <x-reports.navigation />
        <form wire:submit="applyPeriod" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="from" label="From" type="month" min="1000-01" max="9999-12" required />
            <flux:input wire:model="to" label="To" type="month" min="1000-01" max="9999-12" required />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Apply</flux:button>
        </form>
    </div>
    @error('from') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
    @error('to') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

    <x-reports.chart-panel wire:key="report-income-expenses" title="Income vs Expenses" description="Monthly income and expenses for the selected calendar period." canvas="income-expenses-chart">
        <table class="w-full text-left text-sm"><caption class="sr-only">Income vs Expenses data</caption><thead><tr><th scope="col" class="px-4 py-3">Month</th><th scope="col" class="px-4 py-3 text-right">Income</th><th scope="col" class="px-4 py-3 text-right">Expenses</th><th scope="col" class="px-4 py-3 text-right">Remaining</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->report['months'] as $month)<tr wire:key="income-expenses-{{ $month['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $month['label'] }}</th><td class="px-4 py-3 text-right tabular-nums">{{ $month['income'] ?? '—' }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $month['expenses'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $month['remaining'] ?? '—' }}</td></tr>@endforeach</tbody></table>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="report-expenses-by-category" title="Expenses by Category" description="Selected-period expense totals and their share of all expenses." canvas="category-chart">
        <table class="w-full text-left text-sm"><caption class="sr-only">Expenses by Category data</caption><thead><tr><th scope="col" class="px-4 py-3">Category</th><th scope="col" class="px-4 py-3 text-right">Amount</th><th scope="col" class="px-4 py-3 text-right">Percentage</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@forelse ($this->report['categories'] as $category)<tr wire:key="category-{{ $category['key'] }}"><th scope="row" class="px-4 py-3 font-normal"><span class="inline-flex items-center gap-2"><span style="color: {{ $category['color'] }}" aria-hidden="true"><flux:icon :name="$category['icon']" class="size-5" /></span>{{ $category['name'] }}</span></th><td class="px-4 py-3 text-right tabular-nums">{{ $category['amount'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $category['percentage'] }}%</td></tr>@empty<tr><td colspan="3" class="px-4 py-6"><flux:text>No expenses recorded for this period.</flux:text></td></tr>@endforelse</tbody></table>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="report-fixed-vs-variable" title="Fixed vs Variable Expenses" description="Persisted recurring and manual expense snapshots by month." canvas="expense-source-chart">
        <table class="w-full text-left text-sm"><caption class="sr-only">Fixed vs Variable Expenses data</caption><thead><tr><th scope="col" class="px-4 py-3">Month</th><th scope="col" class="px-4 py-3 text-right">Recurring</th><th scope="col" class="px-4 py-3 text-right">Manual</th><th scope="col" class="px-4 py-3 text-right">Total</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->report['months'] as $month)<tr wire:key="source-{{ $month['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $month['label'] }}</th><td class="px-4 py-3 text-right tabular-nums">{{ $month['recurring'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $month['manual'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $month['expenses'] }}</td></tr>@endforeach</tbody></table>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="report-category-evolution" title="Category Evolution" description="Monthly spending for one of your categories.">
        <flux:select wire:model.live="categoryId" label="Category">@if ($this->categories->isEmpty())<option value="">Category unavailable</option>@endif @foreach ($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}{{ $category->is_active ? '' : ' (Inactive)' }}</option>@endforeach</flux:select>
        @error('categoryId') <flux:error name="categoryId" /> @enderror
        <div wire:ignore class="relative mt-4 h-72 w-full" aria-label="Category evolution chart"><canvas id="category-evolution-chart"></canvas></div>
        <table class="mt-4 w-full text-left text-sm"><caption class="sr-only">Category Evolution data</caption><thead><tr><th scope="col" class="px-4 py-3">Month</th><th scope="col" class="px-4 py-3 text-right">Amount</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->report['categoryEvolution'] as $month)<tr wire:key="evolution-{{ $month['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $month['label'] }}</th><td class="px-4 py-3 text-right tabular-nums">{{ $month['amount'] }}</td></tr>@endforeach</tbody></table>
    </x-reports.chart-panel>
</section>
