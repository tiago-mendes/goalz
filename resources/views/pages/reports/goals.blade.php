<?php

use App\Reports\GoalsReport;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Goals Reports')] class extends Component {
    #[Url]
    public ?int $goalId = null;

    public function mount(): void
    {
        $this->selectDefaultGoal();
    }

    public function updatedGoalId(): void
    {
        $this->validate(['goalId' => ['nullable', 'integer']]);

        if ($this->goalId !== null && ! $this->goals->contains('id', $this->goalId)) {
            $this->addError('goalId', 'The selected goal is unavailable.');
            $this->goalId = null;
        }

        if ($this->goalId === null) {
            $this->goalId = $this->goals->first()?->id;
        }

        unset($this->report, $this->chartData);
        $this->dispatch('goals-chart-data-updated', data: $this->chartData);
    }

    #[Computed]
    public function goals(): Collection
    {
        return app(GoalsReport::class)->goals(auth()->user());
    }

    #[Computed]
    public function report(): array
    {
        return app(GoalsReport::class)->handle(auth()->user(), $this->goalId);
    }

    #[Computed]
    public function chartData(): array
    {
        $report = $this->report;
        $numeric = fn (string $value): float => (float) $value;

        return [
            'progress' => [
                'labels' => array_column($report['goals'], 'name'),
                'targets' => array_map($numeric, array_column($report['goals'], 'target')),
                'allocated' => array_map($numeric, array_column($report['goals'], 'allocated')),
            ],
            'fundingSources' => [
                'labels' => array_map(fn (array $source): string => $source['group'].' · '.$source['name'], $report['fundingSources']),
                'amounts' => array_map($numeric, array_column($report['fundingSources'], 'allocated')),
            ],
        ];
    }

    private function selectDefaultGoal(): void
    {
        if ($this->goalId !== null && $this->goals->contains('id', $this->goalId)) {
            return;
        }

        if ($this->goalId !== null) {
            $this->addError('goalId', 'The selected goal is unavailable.');
            $this->goalId = null;
        }

        $this->goalId = $this->goals->first()?->id;
    }
}; ?>

<section data-cash-flow-charts x-data="cashFlowCharts(@js($this->chartData), 'goals')" x-on:goals-chart-data-updated.window="render($event.detail.data)" class="mx-auto w-full max-w-7xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Goals Reports</flux:heading>
            <flux:text>Review the current funding state of your goals and their funding sources.</flux:text>
        </div>
        <x-reports.navigation />
    </div>

    <section class="space-y-4" aria-labelledby="goal-progress-heading">
        <div class="space-y-1">
            <flux:heading size="lg" level="2" id="goal-progress-heading">Goal Progress</flux:heading>
            <flux:text>Current goal funding only; no historical allocation evolution is represented.</flux:text>
        </div>

        @if ($this->report['goals'] === [])
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 dark:border-zinc-600">
                <flux:text>No goals yet. Create a goal to see its current funding progress.</flux:text>
            </div>
        @else
            <x-reports.chart-panel wire:key="goal-progress-chart-panel" title="Target vs Allocated" description="Current target amounts compared with money allocated to each goal." canvas="goal-progress-chart" height="h-[28rem]">
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Goal Progress data</caption><thead><tr><th scope="col" class="px-4 py-3">Goal</th><th scope="col" class="px-4 py-3 text-right">Target</th><th scope="col" class="px-4 py-3 text-right">Allocated</th><th scope="col" class="px-4 py-3 text-right">Remaining</th><th scope="col" class="px-4 py-3 text-right">Progress</th><th scope="col" class="px-4 py-3">Status</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->report['goals'] as $goal)<tr wire:key="goal-progress-{{ $goal['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $goal['name'] }}</th><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$goal['target']" /></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$goal['allocated']" /></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$goal['remaining']" /> @if ($goal['overfunded'] !== '0.00')<span class="block text-xs text-amber-700 dark:text-amber-400">Overfunded by <x-money :currency="auth()->user()->currency" :amount="$goal['overfunded']" /></span>@endif</td><td class="px-4 py-3 text-right tabular-nums">{{ $goal['progress'] }}%</td><td class="px-4 py-3">{{ $goal['status'] }}</td></tr>@endforeach</tbody></table></div>
            </x-reports.chart-panel>
        @endif
    </section>

    <section class="space-y-4" aria-labelledby="goal-funding-sources-heading">
        <div class="space-y-1">
            <flux:heading size="lg" level="2" id="goal-funding-sources-heading">Goal Funding Sources</flux:heading>
            <flux:text>Your Accounts are shown individually. Other members are shown only by contribution total.</flux:text>
        </div>

        @if ($this->report['selectedGoal'] === null)
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 dark:border-zinc-600">
                <flux:text>No goals are available for funding-source details.</flux:text>
            </div>
        @else
            <div class="grid gap-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                <flux:select wire:model.live="goalId" label="Goal">
                    @foreach ($this->goals as $goal)
                        <option value="{{ $goal->id }}">{{ $goal->name }} ({{ $goal->status->label() }})</option>
                    @endforeach
                </flux:select>
                @error('goalId') <flux:error name="goalId" /> @enderror
                <div class="grid gap-4 sm:grid-cols-4">
                    @foreach ([['label' => 'Target', 'key' => 'target'], ['label' => 'Allocated', 'key' => 'allocated'], ['label' => 'Remaining', 'key' => 'remaining'], ['label' => 'Progress', 'key' => 'progress']] as $summary)
                        <div class="space-y-1"><flux:text>{{ $summary['label'] }}</flux:text><p class="font-semibold tabular-nums">@if ($summary['key'] === 'progress'){{ $this->report['selectedSummary'][$summary['key']] }}%@else<x-money :currency="auth()->user()->currency" :amount="$this->report['selectedSummary'][$summary['key']]" />@endif</p></div>
                    @endforeach
                </div>
                <flux:text class="sm:col-span-4">Status: {{ $this->report['selectedSummary']['status'] }}@if ($this->report['selectedSummary']['overfunded'] !== '0.00') · Overfunded by <x-money :currency="auth()->user()->currency" :amount="$this->report['selectedSummary']['overfunded']" />@endif</flux:text>
            </div>

            <x-reports.chart-panel wire:key="goal-funding-sources-chart-panel-{{ $this->goalId }}" title="Current Allocation Distribution" description="Your Accounts and privacy-safe totals from other members; unallocated money is not represented." :canvas="$this->report['fundingSources'] !== [] ? 'goal-funding-sources-chart' : null">
                @if ($this->report['fundingSources'] === [])
                    <flux:text>This goal has no current account allocations.</flux:text>
                @endif
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Goal Funding Sources data</caption><thead><tr><th scope="col" class="px-4 py-3">Group</th><th scope="col" class="px-4 py-3">Source</th><th scope="col" class="px-4 py-3">Details</th><th scope="col" class="px-4 py-3 text-right">Allocated</th><th scope="col" class="px-4 py-3 text-right">Share of Goal Funding</th><th scope="col" class="px-4 py-3">Status</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@forelse ($this->report['fundingSources'] as $source)<tr wire:key="goal-funding-source-{{ $source['key'] }}"><td class="px-4 py-3">{{ $source['group'] }}</td><th scope="row" class="px-4 py-3 font-normal">{{ $source['name'] }}</th><td class="px-4 py-3">{{ $source['type'] }}</td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$source['allocated']" /></td><td class="px-4 py-3 text-right tabular-nums">{{ $source['share'] }}%</td><td class="px-4 py-3">{{ $source['isActive'] === null ? '—' : ($source['isActive'] ? 'Active' : 'Inactive') }}</td></tr>@empty<tr><td colspan="6" class="px-4 py-6"><flux:text>No current account allocations are available.</flux:text></td></tr>@endforelse</tbody></table></div>
            </x-reports.chart-panel>
        @endif
    </section>
</section>
