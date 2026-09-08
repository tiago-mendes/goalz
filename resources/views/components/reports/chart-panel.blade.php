@props(['title', 'description', 'canvas' => null, 'height' => 'h-72'])

<section {{ $attributes }} class="space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="{{ str($title)->slug() }}-heading">
    <div class="space-y-1">
        <flux:heading size="lg" level="2" id="{{ str($title)->slug() }}-heading">{{ $title }}</flux:heading>
        <flux:text>{{ $description }}</flux:text>
    </div>
    @if ($canvas)
        <div wire:ignore class="relative {{ $height }} w-full" aria-label="{{ $title }} chart"><canvas id="{{ $canvas }}"></canvas></div>
    @endif
    {{ $slot }}
</section>
