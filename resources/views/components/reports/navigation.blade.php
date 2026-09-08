<nav aria-label="Reports" class="flex flex-wrap gap-2">
    @foreach ([
        ['route' => 'reports.goals', 'label' => 'Goals'],
        ['route' => 'reports.budgets', 'label' => 'Budgets'],
        ['route' => 'reports.assets', 'label' => 'Assets'],
        ['route' => 'reports.credit-cards', 'label' => 'Credit Cards'],
        ['route' => 'reports.cash-flow', 'label' => 'Cash Flow'],
    ] as $report)
        @if (request()->routeIs($report['route']))
            <flux:button variant="primary" :href="route($report['route'])" wire:navigate>{{ $report['label'] }}</flux:button>
        @else
            <flux:button :href="route($report['route'])" wire:navigate>{{ $report['label'] }}</flux:button>
        @endif
    @endforeach
</nav>
