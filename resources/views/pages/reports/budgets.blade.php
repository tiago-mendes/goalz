<?php

use App\Reports\BudgetReport;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Budget Reports')] class extends Component {
    #[Url(history: true)] public string $month = '';
    public string $selectedMonth = '';

    public function mount(): void
    {
        $this->month = preg_match('/\A[0-9]{4}-[0-9]{2}\z/', $this->month) === 1 ? $this->month : now()->format('Y-m');
        $this->selectedMonth = $this->month;
    }

    public function openMonth(): void
    {
        $this->validate(['month' => ['required', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12']]);
        $this->selectedMonth = $this->month;
        unset($this->report);
    }

    #[Computed]
    public function report(): array
    {
        return app(BudgetReport::class)->handle(auth()->user(), CarbonImmutable::createFromFormat('!Y-m', $this->selectedMonth));
    }
}; ?>

<section class="mx-auto w-full max-w-7xl space-y-6"><div class="flex flex-wrap items-end justify-between gap-4"><div class="space-y-2"><flux:heading size="xl" level="1">Budget Reports</flux:heading><flux:text>Compare your planned and persisted spending for one calendar month.</flux:text></div><x-reports.navigation active-route="reports.budgets" /><form wire:submit="openMonth" class="flex items-end gap-3"><flux:input wire:model="month" label="Month" type="month" min="1000-01" max="9999-12" required /><flux:button type="submit" variant="primary">Open month</flux:button></form></div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([['Total Budget', 'totalBudget'], ['Budgeted Spending', 'budgetedSpending'], ['Remaining', 'remaining']] as [$label, $key])
            <div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading level="3">{{ $label }}</flux:heading><p class="text-xl font-semibold tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$this->report[$key]" /></p></div>
        @endforeach
        <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3"><flux:heading level="3">Overall Usage</flux:heading><span class="text-xl font-semibold tabular-nums">{{ $this->report['overallUsage'] }}%</span></div>
            <div class="space-y-2" aria-label="Budget usage compared with month elapsed">
                <div class="relative h-3 overflow-visible rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="Overall budget usage" aria-valuenow="{{ $this->report['overallUsage'] }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full {{ $this->report['overallPacingStatus'] === 'Over budget' ? 'bg-red-500' : ($this->report['overallPacingStatus'] === 'Above pace' ? 'bg-amber-500' : ($this->report['overallPacingStatus'] === 'Not started' || $this->report['overallPacingStatus'] === 'No budget configured' ? 'bg-zinc-400 dark:bg-zinc-500' : 'bg-emerald-600')) }}" style="width: {{ min(100, (float) $this->report['overallUsage']) }}%"></div>
                    <div class="absolute top-[-4px] h-5 w-0.5 bg-zinc-900 dark:bg-zinc-100" style="left: {{ $this->report['monthElapsed'] }}%" aria-hidden="true"></div>
                </div>
                <div class="flex justify-between gap-3 text-xs text-zinc-600 dark:text-zinc-400"><span>{{ $this->report['overallUsage'] }}% used</span><span>Month elapsed: {{ $this->report['monthElapsed'] }}%</span></div>
            </div>
            <div class="flex items-center gap-2 text-sm {{ $this->report['overallPacingStatus'] === 'Over budget' ? 'text-red-600 dark:text-red-400' : ($this->report['overallPacingStatus'] === 'Above pace' ? 'text-amber-600 dark:text-amber-400' : ($this->report['overallPacingStatus'] === 'On track' ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-600 dark:text-zinc-400')) }}">
                @if ($this->report['overallPacingStatus'] === 'Over budget')<flux:icon name="x-circle" class="size-5" aria-hidden="true" />@elseif ($this->report['overallPacingStatus'] === 'Above pace')<flux:icon name="exclamation-triangle" class="size-5" aria-hidden="true" />@elseif ($this->report['overallPacingStatus'] === 'On track')<flux:icon name="check-circle" class="size-5" aria-hidden="true" />@else<flux:icon name="calendar-days" class="size-5" aria-hidden="true" />@endif
                <span>{{ $this->report['overallPacingStatus'] }}</span>
            </div>
        </div>
    </div>
    @if ($this->report['rows'] === [])<div class="rounded-xl border border-dashed border-zinc-300 p-6 dark:border-zinc-600"><flux:text>No budgets configured for {{ CarbonImmutable::createFromFormat('!Y-m', $selectedMonth)->format('F Y') }}.</flux:text><div class="mt-3"><flux:button size="sm" :href="route('budgets.create')" wire:navigate>Create budget</flux:button></div></div>@else<div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700"><table class="w-full min-w-[64rem] text-left text-sm"><caption class="sr-only">Budget report for {{ $selectedMonth }}</caption><thead class="bg-zinc-50 dark:bg-zinc-900"><tr><th class="px-4 py-3">Category</th><th class="px-4 py-3 text-right">Budget</th><th class="px-4 py-3 text-right">Expenses</th><th class="px-4 py-3 text-right">Remaining</th><th class="px-4 py-3">Usage</th><th class="px-4 py-3">Status</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->report['rows'] as $row)<tr wire:key="budget-report-{{ $row['key'] }}"><td class="px-4 py-3"><span class="inline-flex items-center gap-2"><span style="color: {{ $row['category']->safeColor() }}" aria-hidden="true"><flux:icon :name="$row['category']->safeIcon()" class="size-5" /></span>{{ $row['category']->name }}</span></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$row['budget']" /></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$row['expenses']" /></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$row['remaining']" /></td><td class="min-w-48 px-4 py-3"><div class="flex items-center gap-2"><div class="relative h-2 min-w-20 flex-1 overflow-visible rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="{{ $row['category']->name }} budget usage" aria-valuenow="{{ $row['usage'] }}" aria-valuemin="0" aria-valuemax="100"><div class="relative z-0 h-full overflow-hidden rounded-full"><div class="h-full rounded-full {{ $row['status'] === 'Over budget' ? 'bg-red-500' : ($row['status'] === 'Above pace' ? 'bg-amber-500' : ($row['status'] === 'Not started' ? 'bg-zinc-400 dark:bg-zinc-500' : 'bg-emerald-600')) }}" style="width: {{ min(100, (float) $row['usage']) }}%"></div></div><div class="absolute top-[-4px] z-10 h-5 w-0.5 bg-zinc-900 dark:bg-zinc-100" style="left: {{ $this->report['monthElapsed'] }}%" title="Month elapsed: {{ $this->report['monthElapsed'] }}%" aria-label="Month elapsed: {{ $this->report['monthElapsed'] }}%"></div></div><span class="tabular-nums">{{ $row['usage'] }}%</span></div></td><td class="px-4 py-3"><flux:badge :color="$row['status'] === 'Over budget' ? 'red' : ($row['status'] === 'Above pace' ? 'yellow' : ($row['status'] === 'Not started' || $row['status'] === 'No budget configured' ? 'zinc' : 'green'))"><span class="inline-flex items-center gap-1">@if ($row['status'] === 'Over budget')<flux:icon name="x-circle" class="size-4" aria-hidden="true" />@elseif ($row['status'] === 'Above pace')<flux:icon name="exclamation-triangle" class="size-4" aria-hidden="true" />@elseif ($row['status'] === 'On track')<flux:icon name="check-circle" class="size-4" aria-hidden="true" />@endif{{ $row['status'] }}</span></flux:badge></td></tr>@endforeach</tbody></table></div>@endif
</section>
