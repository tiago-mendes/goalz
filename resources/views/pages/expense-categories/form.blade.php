<?php

use App\Models\ExpenseCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage category')] class extends Component {
    #[Locked]
    public ?int $categoryId = null;
    public string $name = '';
    public string $icon = ExpenseCategory::DEFAULT_ICON;
    public string $color = ExpenseCategory::DEFAULT_COLOR;
    public $is_active = true;

    public function boot(): void
    {
        Gate::authorize('viewAny', ExpenseCategory::class);
    }

    public function mount(?int $categoryId = null): void
    {
        if ($categoryId !== null) {
            $category = auth()->user()->expenseCategories()->find($categoryId);
            abort_if($category === null, 404);
            Gate::authorize('view', $category);
            $this->categoryId = $category->id;
            $this->name = $category->name;
            $this->icon = $category->safeIcon();
            $this->color = $category->safeColor();
            $this->is_active = $category->is_active;
        }
    }

    public function save(): void
    {
        $category = $this->categoryId === null
            ? auth()->user()->expenseCategories()->make()
            : auth()->user()->expenseCategories()->find($this->categoryId);
        abort_if($category === null, 404);
        Gate::authorize($category->exists ? 'update' : 'create', $category->exists ? $category : ExpenseCategory::class);

        $this->name = trim($this->name);
        if ($this->icon === '') {
            $this->icon = ExpenseCategory::DEFAULT_ICON;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->where('user_id', auth()->id())->ignore($category)],
            'icon' => ['required', 'string', Rule::in(array_keys(ExpenseCategory::ICONS))],
            'color' => ['required', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
            'is_active' => ['required', 'boolean'],
        ]);
        $validated['color'] = strtoupper($validated['color']);

        try {
            $category->fill($validated)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'The name has already been taken.']);
        }

        session()->flash('status', 'Category saved.');
        $this->redirectRoute('expense-categories.index', navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <flux:button :href="route('expense-categories.index')" wire:navigate>Back to categories</flux:button>
    <flux:heading size="xl" level="1">{{ $categoryId ? 'Edit category' : 'Create category' }}</flux:heading>
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" maxlength="100" required autocomplete="off" />
        <flux:fieldset>
            <flux:legend>Icon</flux:legend>
            <div class="grid grid-cols-[repeat(auto-fit,2.5rem)] gap-2">
                @foreach (ExpenseCategory::ICONS as $value => $label)
                    <flux:button
                        type="button"
                        :icon="$value"
                        :variant="$icon === $value ? 'primary' : 'outline'"
                        :aria-label="$label"
                        :title="$label"
                        :aria-pressed="$icon === $value ? 'true' : 'false'"
                        wire:click="$set('icon', '{{ $value }}')"
                        wire:key="icon-{{ $value }}"
                    />
                @endforeach
            </div>
            <flux:error name="icon" />
        </flux:fieldset>
        <flux:input wire:model="color" label="Color" type="color" required />
        <flux:checkbox wire:model="is_active" label="Active category" />
        <flux:text>Inactive categories stay here so you can activate them again later.</flux:text>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save category</flux:button>
    </form>
</section>
