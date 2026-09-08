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

<section class="mx-auto w-full max-w-7xl space-y-6"><div class="flex flex-wrap items-end justify-between gap-4"><div class="space-y-2"><flux:heading size="xl" level="1">Budget Reports</flux:heading><flux:text>Compare your planned and persisted spending for one calendar month.</flux:text></div><x-reports.navigation /><form wire:submit="openMonth" class="flex items-end gap-3"><flux:input wire:model="month" label="Month" type="month" min="1000-01" max="9999-12" required /><flux:button type="submit" variant="primary">Open month</flux:button></form></div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@foreach ([['Total Budget', 'totalBudget'], ['Budgeted Spending', 'budgetedSpending'], ['Remaining', 'remaining'], ['Overall Usage', 'overallUsage']] as [$label, $key])<div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><flux:heading level="3">{{ $label }}</flux:heading><p class="text-xl font-semibold tabular-nums">@if ($key === 'overallUsage'){{ $this->report[$key] }}%@else<x-money :currency="auth()->user()->currency" :amount="$this->report[$key]" />@endif</p></div>@endforeach</div>
    @if ($this->report['rows'] === [])<div class="rounded-xl border border-dashed border-zinc-300 p-6 dark:border-zinc-600"><flux:text>No budgets configured for {{ CarbonImmutable::createFromFormat('!Y-m', $selectedMonth)->format('F Y') }}.</flux:text><div class="mt-3"><flux:button size="sm" :href="route('budgets.create')" wire:navigate>Create budget</flux:button></div></div>@else<div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700"><table class="w-full min-w-[64rem] text-left text-sm"><caption class="sr-only">Budget report for {{ $selectedMonth }}</caption><thead class="bg-zinc-50 dark:bg-zinc-900"><tr><th class="px-4 py-3">Category</th><th class="px-4 py-3 text-right">Budget</th><th class="px-4 py-3 text-right">Expenses</th><th class="px-4 py-3 text-right">Remaining</th><th class="px-4 py-3">Usage</th><th class="px-4 py-3">Status</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->report['rows'] as $row)<tr wire:key="budget-report-{{ $row['key'] }}"><td class="px-4 py-3"><span class="inline-flex items-center gap-2"><span style="color: {{ $row['category']->safeColor() }}" aria-hidden="true"><flux:icon :name="$row['category']->safeIcon()" class="size-5" /></span>{{ $row['category']->name }}</span></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$row['budget']" /></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$row['expenses']" /></td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$row['remaining']" /></td><td class="min-w-48 px-4 py-3"><div class="flex items-center gap-2"><div class="h-2 min-w-20 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="{{ $row['category']->name }} budget usage" aria-valuenow="{{ $row['usage'] }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full {{ $row['status'] === 'Over budget' ? 'bg-red-500' : ($row['status'] === 'Budget reached' ? 'bg-amber-500' : 'bg-emerald-600') }}" style="width: {{ min(100, (float) $row['usage']) }}%"></div></div><span class="tabular-nums">{{ $row['usage'] }}%</span></div></td><td class="px-4 py-3"><flux:badge :color="$row['status'] === 'Over budget' ? 'red' : ($row['status'] === 'Budget reached' ? 'yellow' : 'green')">{{ $row['status'] }}</flux:badge></td></tr>@endforeach</tbody></table></div>@endif
</section>
