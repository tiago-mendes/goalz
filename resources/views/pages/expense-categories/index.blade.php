<?php

use App\Models\ExpenseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Categories')] class extends Component {
    use WithPagination;

    public function boot(): void
    {
        Gate::authorize('viewAny', ExpenseCategory::class);
    }

    #[Computed]
    public function categories(): LengthAwarePaginator
    {
        return auth()->user()->expenseCategories()->orderBy('name')->orderBy('id')->paginate(20);
    }

    public function setActive(int $categoryId, bool $active): void
    {
        $category = auth()->user()->expenseCategories()->find($categoryId);
        abort_if($category === null, 404);
        Gate::authorize('update', $category);
        $category->is_active = $active;
        $category->save();
        unset($this->categories);
        session()->flash('status', $active ? 'Category activated.' : 'Category deactivated.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">Categories</flux:heading>
        <flux:button variant="primary" :href="route('expense-categories.create')" wire:navigate>Create category</flux:button>
    </div>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your expense categories</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->categories as $category)
                    <tr wire:key="category-{{ $category->id }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span style="color: {{ $category->safeColor() }}" aria-hidden="true"><flux:icon :name="$category->safeIcon()" class="size-5" /></span>
                                <span>{{ $category->name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3"><flux:badge :color="$category->is_active ? 'green' : 'zinc'">{{ $category->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <flux:button size="sm" :href="route('expense-categories.edit', $category->id)" :aria-label="'Edit '.$category->name" wire:navigate>Edit</flux:button>
                                <flux:button size="sm" wire:click="setActive({{ $category->id }}, {{ $category->is_active ? 'false' : 'true' }})" wire:confirm="Change this category's active status?" wire:loading.attr="disabled" :aria-label="($category->is_active ? 'Deactivate ' : 'Activate ').$category->name">{{ $category->is_active ? 'Deactivate' : 'Activate' }}</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6"><flux:text>No categories yet. Create your first category to get started.</flux:text></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->categories->links() }}
</section>
