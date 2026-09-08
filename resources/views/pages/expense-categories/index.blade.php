<?php

use App\Models\ExpenseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Categories')] class extends Component {
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = '';

    #[Url(history: true)]
    public string $sort = 'name';

    #[Url(history: true)]
    public string $direction = 'asc';

    public function boot(): void
    {
        Gate::authorize('viewAny', ExpenseCategory::class);
    }

    public function mount(): void
    {
        $this->normalizeFilters();
    }

    #[Computed]
    public function categories(): LengthAwarePaginator
    {
        $query = auth()->user()->expenseCategories();
        $search = trim($this->search);

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if ($this->status === 'active') {
            $query->where('is_active', true);
        } elseif ($this->status === 'inactive') {
            $query->where('is_active', false);
        }

        $query->orderBy($this->sort, $this->direction)->orderBy('id');

        return $query->paginate(20);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->sort = 'name';
        $this->direction = 'asc';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'sort', 'direction'], true)) {
            $this->normalizeFilters();
            $this->resetPage();
        }
    }

    public function hasActiveFilters(): bool
    {
        return trim($this->search) !== '' || $this->status !== '';
    }

    private function normalizeFilters(): void
    {
        $this->search = trim($this->search);
        $this->status = in_array($this->status, ['active', 'inactive'], true) ? $this->status : '';
        $this->sort = in_array($this->sort, ['name', 'is_active'], true) ? $this->sort : 'name';
        $this->direction = in_array($this->direction, ['asc', 'desc'], true) ? $this->direction : 'asc';
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
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 sm:flex-row sm:flex-wrap sm:items-end">
        <flux:input class="min-w-56 sm:flex-1" wire:model.live.debounce.350ms="search" label="Search" placeholder="Search categories..." type="search" />
        <flux:select wire:model.live="status" label="Status" class="min-w-40">
            <flux:select.option value="">All</flux:select.option>
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="inactive">Inactive</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="sort" label="Sort" class="min-w-48">
            <flux:select.option value="name">Name A-Z</flux:select.option>
            <flux:select.option value="is_active">Status</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="direction" label="Direction" class="min-w-32">
            <flux:select.option value="asc">Ascending</flux:select.option>
            <flux:select.option value="desc">Descending</flux:select.option>
        </flux:select>
        @if ($this->hasActiveFilters())
            <flux:button wire:click="clearFilters">Clear filters</flux:button>
        @endif
    </div>
    <flux:text>{{ $this->categories->total() }} {{ $this->categories->total() === 1 ? 'category' : 'categories' }}</flux:text>
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
                    <tr><td colspan="3" class="px-4 py-6">
                        <flux:text>{{ $this->hasActiveFilters() ? 'No categories match your current filters.' : 'No categories yet. Create your first category to get started.' }}</flux:text>
                        @if ($this->hasActiveFilters()) <flux:button size="sm" wire:click="clearFilters">Clear filters</flux:button> @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->categories->links() }}
</section>
