@props(['activeRoute' => null])

@php($currentRoute = $activeRoute ?? request()->route()?->getName())

<nav aria-label="Reports" class="flex flex-wrap gap-2">
    @foreach ([
        ['route' => 'reports.goals', 'label' => 'Goals'],
        ['route' => 'reports.budgets', 'label' => 'Budgets'],
        ['route' => 'reports.assets', 'label' => 'Assets'],
        ['route' => 'reports.credit-cards', 'label' => 'Credit Cards'],
        ['route' => 'reports.cash-flow', 'label' => 'Cash Flow'],
    ] as $report)
        @php($isActive = $currentRoute === $report['route'] || request()->routeIs($report['route']))
        @if ($isActive)
            <flux:button variant="primary" :aria-current="$isActive ? 'page' : null" :href="route($report['route'])" wire:navigate>{{ $report['label'] }}</flux:button>
        @else
            <flux:button :href="route($report['route'])" wire:navigate>{{ $report['label'] }}</flux:button>
        @endif
    @endforeach
</nav>
