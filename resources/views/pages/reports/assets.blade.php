<?php

use App\Reports\AssetsReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Assets Reports')] class extends Component {
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $accountId = null;

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
        $this->selectDefaultAccount();
    }

    public function applyPeriod(): void
    {
        $this->validatePeriod();
        $this->selectedFrom = $this->from;
        $this->selectedTo = $this->to;
        unset($this->report, $this->chartData);
        $this->dispatch('assets-chart-data-updated', data: $this->chartData);
    }

    public function updatedAccountId(): void
    {
        $this->validate(['accountId' => ['nullable', 'integer']]);

        if ($this->accountId !== null && ! $this->accounts->contains('id', $this->accountId)) {
            $this->addError('accountId', 'The selected account is unavailable.');
            $this->accountId = null;
        }

        if ($this->accountId === null) {
            $this->accountId = $this->accounts->first()?->id;
        }

        unset($this->report, $this->chartData);
        $this->dispatch('assets-chart-data-updated', data: $this->chartData);
    }

    #[Computed]
    public function accounts(): Collection
    {
        return app(AssetsReport::class)->accounts(auth()->user());
    }

    #[Computed]
    public function report(): array
    {
        return app(AssetsReport::class)->handle(
            auth()->user(),
            CarbonImmutable::createFromFormat('!Y-m', $this->selectedFrom),
            CarbonImmutable::createFromFormat('!Y-m', $this->selectedTo),
            $this->accountId,
        );
    }

    #[Computed]
    public function chartData(): array
    {
        $report = $this->report;
        $numeric = fn (string $value): float => (float) $value;

        return [
            'assetTypes' => [
                'labels' => array_column($report['accountTypes'], 'name'),
                'amounts' => array_map($numeric, array_column($report['accountTypes'], 'amount')),
                'colors' => array_column($report['accountTypes'], 'color'),
            ],
            'distribution' => [
                'labels' => array_column($report['distribution'], 'name'),
                'amounts' => array_map($numeric, array_column($report['distribution'], 'balance')),
            ],
            'allocation' => [
                'labels' => ['Allocated', 'Free', 'Overallocated'],
                'amounts' => [
                    $numeric($report['allocatedAssets']),
                    $numeric($report['freeAssets']),
                    $numeric($report['overallocatedAssets']),
                ],
            ],
            'evolution' => [
                'labels' => array_column($report['accountEvolution'], 'label'),
                'amounts' => array_map($numeric, array_column($report['accountEvolution'], 'balance')),
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
        validator([], [])->after(function ($validator) use ($from, $to): void {
            if ($from->greaterThan($to)) {
                $validator->errors()->add('to', 'The end month must be on or after the start month.');
            }
            if ($from->diffInMonths($to) >= 60) {
                $validator->errors()->add('to', 'The reporting period cannot exceed 60 months.');
            }
        })->validate();
    }

    private function selectDefaultAccount(): void
    {
        if ($this->accountId !== null && $this->accounts->contains('id', $this->accountId)) {
            return;
        }

        if ($this->accountId !== null) {
            $this->addError('accountId', 'The selected account is unavailable.');
        }

        $this->accountId = $this->accounts->first()?->id;
    }
}; ?>

<div>
<section data-cash-flow-charts x-data="cashFlowCharts(@js($this->chartData), 'assets')" x-on:assets-chart-data-updated.window="render($event.detail.data)" class="mx-auto w-full max-w-7xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">Assets Reports</flux:heading>
            <flux:text>Review your current assets and recorded account balance history.</flux:text>
        </div>
        <x-reports.navigation />
    </div>

    @error('from') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
    @error('to') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

    <section class="space-y-4" aria-labelledby="current-financial-position-heading">
        <div class="space-y-1">
            <flux:heading size="lg" level="2" id="current-financial-position-heading">Current Financial Position</flux:heading>
            <flux:text>Current balances from your active accounts.</flux:text>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ([['label' => 'Total Assets', 'description' => 'Current balances from active accounts', 'key' => 'totalAssets'], ['label' => 'Total Allocated', 'description' => 'Designated to goals', 'key' => 'allocatedAssets'], ['label' => 'Total Available', 'description' => 'Available in active accounts', 'key' => 'freeAssets']] as $card)
                <div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg">{{ $card['label'] }}</flux:heading>
                    <flux:text>{{ $card['description'] }}</flux:text>
                    <p class="break-words text-2xl font-semibold tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$this->report[$card['key']]" /></p>
                </div>
            @endforeach
        </div>
        @if ($this->report['overallocatedAccounts'] > 0)
            <flux:callout color="amber" icon="exclamation-triangle">
                <flux:callout.heading>Allocation warning</flux:callout.heading>
                <flux:callout.text>{{ $this->report['overallocatedAccounts'] }} {{ str('account')->plural($this->report['overallocatedAccounts']) }} have allocations above the current balance, totaling <x-money :currency="auth()->user()->currency" :amount="$this->report['overallocatedAssets']" />.</flux:callout.text>
            </flux:callout>
        @endif
    </section>

    <x-reports.chart-panel wire:key="assets-by-account-type" title="Assets by Account Type" description="Current balances grouped by active account type." :canvas="$this->report['totalAssets'] === '0.00' ? null : 'assets-by-type-chart'">
        @if ($this->report['totalAssets'] === '0.00')
            <flux:text>No active asset balance is available to chart.</flux:text>
        @endif
        <table class="w-full text-left text-sm"><caption class="sr-only">Assets by Account Type data</caption><thead><tr><th scope="col" class="px-4 py-3">Type</th><th scope="col" class="px-4 py-3 text-right">Amount</th><th scope="col" class="px-4 py-3 text-right">Percentage</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ($this->report['accountTypes'] as $type)<tr wire:key="asset-type-{{ $type['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $type['name'] }}</th><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$type['amount']" /></td><td class="px-4 py-3 text-right tabular-nums">{{ $type['percentage'] }}%</td></tr>@endforeach</tbody></table>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="account-distribution" title="Account Distribution" description="Current balances for each active account." :canvas="$this->report['distribution'] !== [] ? 'account-distribution-chart' : null">
        @if ($this->report['distribution'] === [])
            <flux:text>No active accounts are available to chart.</flux:text>
        @endif
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Account Distribution data</caption><thead><tr><th scope="col" class="px-4 py-3">Account</th><th scope="col" class="px-4 py-3">Type</th><th scope="col" class="px-4 py-3 text-right">Balance</th><th scope="col" class="px-4 py-3 text-right">Share</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@forelse ($this->report['distribution'] as $account)<tr wire:key="asset-distribution-{{ $account['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $account['name'] }}</th><td class="px-4 py-3">{{ $account['type'] }}</td><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$account['balance']" /></td><td class="px-4 py-3 text-right tabular-nums">{{ $account['share'] }}%</td></tr>@empty<tr><td colspan="4" class="px-4 py-6"><flux:text>No active accounts are available.</flux:text></td></tr>@endforelse</tbody></table></div>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="allocated-vs-free-assets" title="Allocated vs Free Assets" description="Current account balances and their allocation status." canvas="asset-allocation-chart">
        <table class="w-full text-left text-sm"><caption class="sr-only">Allocated vs Free Assets data</caption><thead><tr><th scope="col" class="px-4 py-3">Designation</th><th scope="col" class="px-4 py-3 text-right">Amount</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@foreach ([['label' => 'Allocated', 'key' => 'allocatedAssets'], ['label' => 'Free', 'key' => 'freeAssets'], ['label' => 'Overallocated', 'key' => 'overallocatedAssets']] as $status)<tr wire:key="asset-allocation-{{ $status['key'] }}"><th scope="row" class="px-4 py-3 font-normal">{{ $status['label'] }}</th><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$this->report[$status['key']]" /></td></tr>@endforeach</tbody></table>
    </x-reports.chart-panel>

    <x-reports.chart-panel wire:key="account-balance-evolution" title="Account Balance Evolution" description="Recorded balance snapshots for one account during the selected period.">
        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
            <flux:select wire:model.live="accountId" label="Account" :disabled="$this->accounts->isEmpty()">@if ($this->accounts->isEmpty())<option value="">No accounts available</option>@endif @foreach ($this->accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}{{ $account->is_active ? '' : ' (Inactive)' }}</option>@endforeach</flux:select>
            <form wire:submit="applyPeriod" class="flex flex-wrap items-end gap-3">
                <flux:input wire:model="from" label="From" type="month" min="1000-01" max="9999-12" required />
                <flux:input wire:model="to" label="To" type="month" min="1000-01" max="9999-12" required />
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Apply</flux:button>
            </form>
        </div>
        @error('accountId') <flux:error name="accountId" /> @enderror
        @if ($this->accountId !== null)
            <div wire:ignore class="relative mt-4 h-72 w-full" aria-label="Account balance evolution chart"><canvas id="account-balance-evolution-chart"></canvas></div>
            <div class="overflow-x-auto"><table class="mt-4 w-full text-left text-sm"><caption class="sr-only">Account Balance Evolution data</caption><thead><tr><th scope="col" class="px-4 py-3">Recorded</th><th scope="col" class="px-4 py-3 text-right">Balance</th></tr></thead><tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">@forelse ($this->report['accountEvolution'] as $snapshot)<tr wire:key="account-evolution-{{ $snapshot['key'] }}"><th scope="row" class="whitespace-nowrap px-4 py-3 font-normal">{{ $snapshot['label'] }}</th><td class="px-4 py-3 text-right tabular-nums"><x-money :currency="auth()->user()->currency" :amount="$snapshot['balance']" /></td></tr>@empty<tr><td colspan="2" class="px-4 py-6"><flux:text>No balance snapshots are recorded in this period.</flux:text></td></tr>@endforelse</tbody></table></div>
        @else
            <flux:text>No accounts are available for balance evolution.</flux:text>
        @endif
    </x-reports.chart-panel>
</section>
</div>
