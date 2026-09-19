<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        {{ $slot }}
    </flux:main>
    <livewire:goal-achievement-celebration />
</x-layouts::app.sidebar>
