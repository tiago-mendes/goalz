@props([
    'categories',
    'model',
    'selectedId' => null,
    'label' => 'Category',
    'placeholder' => 'Choose a category',
    'required' => false,
])

@php
    $selectedCategory = $categories->firstWhere('id', (int) $selectedId);
@endphp

<flux:field {{ $attributes }}>
    <flux:label>{{ $label }}</flux:label>
    <input type="hidden" wire:model="{{ $model }}" />
    <flux:dropdown class="w-full">
        <flux:button
            type="button"
            class="w-full justify-between"
            icon:trailing="chevron-down"
            :aria-label="$selectedCategory?->name ?? $placeholder"
            :aria-required="$required ? 'true' : 'false'"
        >
            <span class="flex min-w-0 items-center gap-2">
                @if ($selectedCategory)
                    <span class="shrink-0" style="color: {{ $selectedCategory->safeColor() }}" aria-hidden="true"><flux:icon :name="$selectedCategory->safeIcon()" class="size-4" /></span>
                    <span class="truncate">{{ $selectedCategory->name }}{{ $selectedCategory->is_active ? '' : ' (Inactive — current category)' }}</span>
                @else
                    <span class="truncate text-zinc-400">{{ $placeholder }}</span>
                @endif
            </span>
        </flux:button>
        <flux:menu class="max-h-72 overflow-y-auto">
            @if (! $required)
                <flux:menu.item wire:click="$set('{{ $model }}', '')">{{ $model === 'category' ? 'All categories' : $placeholder }}</flux:menu.item>
            @endif
            @foreach ($categories as $category)
                <flux:menu.item
                    wire:key="{{ $model }}-category-{{ $category->id }}"
                    wire:click="$set('{{ $model }}', '{{ $category->id }}')"
                    :icon="$category->safeIcon()"
                    class="**:data-flux-menu-item-icon:text-[var(--category-color)]"
                    style="--category-color: {{ $category->safeColor() }}"
                >{{ $category->name }}{{ $category->is_active ? '' : ' (Inactive — current category)' }}</flux:menu.item>
            @endforeach
        </flux:menu>
    </flux:dropdown>
    <flux:error name="{{ $model }}" />
</flux:field>
