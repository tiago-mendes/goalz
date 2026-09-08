<?php

use App\Reports\CreditCardReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Credit Card Reports')] class extends Component {
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $billCardId = null;

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
        $this->selectDefaultCard();
    }

    public function applyPeriod(): void
    {
        $this->validatePeriod();
        $this->selectedFrom = $this->from;
        $this->selectedTo = $this->to;
        unset($this->report, $this->chartData);
        $this->dispatch('credit-card-chart-data-updated', data: $this->chartData);
    }

    public function updatedBillCardId(): void
    {
        $this->validate(['billCardId' => ['nullable', 'integer']]);

        if ($this->billCardId !== null && ! $this->cards->contains('id', $this->billCardId)) {
            $this->addError('billCardId', 'The selected credit card is unavailable.');
            $this->billCardId = null;
        }

        unset($this->report, $this->chartData);
        $this->dispatch('credit-card-chart-data-updated', data: $this->chartData);
    }

    #[Computed]
    public function cards(): Collection
    {
        return app(CreditCardReport::class)->cards(auth()->user());
    }

    #[Computed]
    public function report(): array
    {
        return app(CreditCardReport::class)->handle(
            auth()->user(),
            CarbonImmutable::createFromFormat('!Y-m', $this->selectedFrom),
            CarbonImmutable::createFromFormat('!Y-m', $this->selectedTo),
            $this->billCardId,
        );
    }

    #[Computed]
    public function chartData(): array
    {
        $report = $this->report;
        $numeric = fn (string $value): float => (float) $value;

        return [
            'monthly' => [
                'labels' => array_column($report['months'], 'label'),
                'amounts' => array_map($numeric, array_column($report['months'], 'amount')),
            ],
            'cards' => [
                'labels' => array_column($report['cards'], 'name'),
                'amounts' => array_map($numeric, array_column($report['cards'], 'amount')),
            ],
            'categories' => [
                'labels' => array_column($report['categories'], 'name'),
                'amounts' => array_map($numeric, array_column($report['categories'], 'amount')),
                'colors' => array_column($report['categories'], 'color'),
            ],
            'bills' => [
                'labels' => array_column($report['billMonths'], 'label'),
                'amounts' => array_map($numeric, array_column($report['billMonths'], 'amount')),
            ],
        ];
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

    private function selectDefaultCard(): void
    {
        if ($this->billCardId === null || $this->cards->contains('id', $this->billCardId)) {
            return;
        }

        $this->addError('billCardId', 'The selected credit card is unavailable.');
        $this->billCardId = null;
    }
}; ?>

<section data-cash-flow-charts x-data="cashFlowCharts(@js($this->chartData), 'credit-cards')" x-on:credit-card-chart-data-updated.window="render($event.detail.data)" class="mx-auto w-full max-w-7xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Credit Card Reports</flux:heading>
            <flux:text>Analyze persisted credit card spending and calculated bill due months.</flux:text>
        </div>
        <nav aria-label="Reports" class="flex flex-wrap gap-2">
            <flux:button :href="route('reports.cash-flow')" wire:navigate>Cash Flow</flux:button>
            <flux:button :href="route('reports.assets')" wire:navigate>Assets</flux:button>
            <flux:button :href="route('reports.goals')" wire:navigate>Goals</flux:button>
            <flux:button variant="primary" :href="route('reports.credit-cards')" wire:navigate>Credit Cards</flux:button>
            <flux:button :href="route('reports.budgets')" wire:navigate>Budgets</flux:button>
        </nav>
    </div>

    <form wire:submit="applyPeriod" class="flex flex-wrap items-end gap-3">
        <flux:input wire:model="from" label="From" type="month" min="1000-01" max="9999-12" required />
        <flux:input wire:model="to" label="To" type="month" min="1000-01" max="9999-12" required />
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Apply</flux:button>
    </form>
    @error('from') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
    @error('to') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

    <section class="space-y-4" aria-labelledby="credit-card-spending-heading">
        <div class="space-y-1">
            <flux:heading size="lg" level="2" id="credit-card-spending-heading">Credit Card Spending</flux:heading>
            <flux:text>Spending is grouped by the purchase calendar month.</flux:text>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ([['label' => 'Total Credit Card Spending', 'key' => 'total'], ['label' => 'Card Expenses', 'key' => 'expenseCount'], ['label' => 'Average Monthly Card Spending', 'key' => 'averageMonthly']] as $card)
                <div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading size="lg">{{ $card['label'] }}</flux:heading><p class="break-words text-2xl font-semibold tabular-nums">{{ $card['key'] === 'expenseCount' ? $this->report[$card['key']] : auth()->user()->currency.' '.$this->report[$card['key']] }}</p></div>
            @endforeach
        </div>
    </section>

    <x-reports.chart-panel wire:key="credit-card-monthly-spending" title="Monthly Credit Card Spending" description="Non-deleted persisted credit card Expenses grouped by purchase month." canvas="credit-card-monthly-spending-chart">
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Monthly Credit Card Spending data</caption><thead><tr><th scope="col" class="px-4 py-3">Month</th><th scope="col" class="px-4 py-3 text-right">Spending</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->report['months'] as $month)<tr wire:key="credit-card-month-{{ $month['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $month['label'] }}</th><td class="px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $month['amount'] }}</td></tr>@endforeach</tbody></table></div>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="credit-card-spending-by-card" title="Spending by Card" description="Selected-period purchase spending by credit card." :canvas="$this->report['cards'] !== [] ? 'credit-card-spending-by-card-chart' : null">
        @if ($this->report['cards'] === []) <flux:text>No credit card spending is available for this period.</flux:text> @endif
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Spending by Card data</caption><thead><tr><th scope="col" class="px-4 py-3">Card</th><th scope="col" class="px-4 py-3 text-right">Amount</th><th scope="col" class="px-4 py-3 text-right">Percentage</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@forelse ($this->report['cards'] as $card)<tr wire:key="credit-card-total-{{ $card['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $card['name'] }}</th><td class="px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $card['amount'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $card['percentage'] }}%</td></tr>@empty<tr><td colspan="3" class="px-4 py-6"><flux:text>No card spending is available.</flux:text></td></tr>@endforelse</tbody></table></div>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="credit-card-spending-by-category" title="Spending by Category" description="Selected-period credit card spending grouped by expense category." :canvas="$this->report['categories'] !== [] ? 'credit-card-spending-by-category-chart' : null">
        @if ($this->report['categories'] === []) <flux:text>No credit card categories are available for this period.</flux:text> @endif
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Spending by Category data</caption><thead><tr><th scope="col" class="px-4 py-3">Category</th><th scope="col" class="px-4 py-3 text-right">Amount</th><th scope="col" class="px-4 py-3 text-right">Percentage</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@forelse ($this->report['categories'] as $category)<tr wire:key="credit-card-category-{{ $category['key'] }}"><th scope="row" class="px-4 py-3 font-normal"><span class="inline-flex items-center gap-2"><span style="color: {{ $category['color'] }}" aria-hidden="true"><flux:icon :name="$category['icon']" class="size-5" /></span>{{ $category['name'] }}</span></th><td class="px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $category['amount'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ $category['percentage'] }}%</td></tr>@empty<tr><td colspan="3" class="px-4 py-6"><flux:text>No category spending is available.</flux:text></td></tr>@endforelse</tbody></table></div>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="credit-card-calculated-bills" title="Calculated Bills by Due Month" description="Calculated bills use each card's current billing-cycle settings and persisted Expenses only.">
        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
            <flux:select wire:model.live="billCardId" label="Card"><option value="">All Credit Cards</option>@foreach ($this->cards as $card)<option value="{{ $card->id }}">{{ $card->name }}{{ $card->is_active ? '' : ' (Inactive)' }}</option>@endforeach</flux:select>
            @error('billCardId') <flux:error name="billCardId" /> @enderror
        </div>
        @if ($this->report['bills'] === [])
            <flux:text class="mt-4">No calculated bills are available for this period.</flux:text>
        @else
            <div wire:ignore class="relative mt-4 h-72 w-full" aria-label="Calculated bills by due month chart"><canvas id="credit-card-bill-evolution-chart"></canvas></div>
        @endif
        <div class="overflow-x-auto"><table class="mt-4 w-full text-left text-sm"><caption class="sr-only">Calculated Bills by Due Month data</caption><thead><tr><th scope="col" class="px-4 py-3">Due Month</th><th scope="col" class="px-4 py-3">Card</th><th scope="col" class="px-4 py-3">Cycle</th><th scope="col" class="px-4 py-3">Due Date</th><th scope="col" class="px-4 py-3 text-right">Amount</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@forelse ($this->report['bills'] as $bill)<tr wire:key="credit-card-bill-{{ $bill['key'] }}"><th scope="row" class="whitespace-nowrap px-4 py-3 font-normal">{{ $bill['dueMonth'] }}</th><td class="px-4 py-3">{{ $bill['card'] }}</td><td class="px-4 py-3 whitespace-nowrap">{{ $bill['cycle'] }}</td><td class="px-4 py-3 whitespace-nowrap">{{ $bill['dueDate'] }}</td><td class="px-4 py-3 text-right tabular-nums">{{ auth()->user()->currency }} {{ $bill['amount'] }}</td></tr>@empty<tr><td colspan="5" class="px-4 py-6"><flux:text>No calculated bill rows are available.</flux:text></td></tr>@endforelse</tbody></table></div>
    </x-reports.chart-panel>
</section>
