<?php

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Admin users')] class extends Component {
    use WithPagination;

    public function boot(): void
    {
        Gate::authorize('manage-users');
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        Gate::authorize('manage-users');

        return User::select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
            ->orderByDesc('id')->paginate(20);
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:text>Admin</flux:text>
            <flux:heading size="xl" level="1">Users</flux:heading>
        </div>
        <flux:button variant="primary" :href="route('admin.users.create')" wire:navigate>Create user</flux:button>
    </div>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">User accounts</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    @foreach (['Name', 'Email', 'Role', 'Status', 'Created', 'Actions'] as $heading)
                        <th scope="col" class="px-4 py-3">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->users as $user)
                    <tr wire:key="user-{{ $user->id }}">
                        <td class="px-4 py-3">{{ $user->name }}</td>
                        <td class="px-4 py-3">{{ $user->email }}</td>
                        <td class="px-4 py-3">{{ ucfirst($user->role->value) }}</td>
                        <td class="px-4 py-3"><flux:badge :color="$user->is_active ? 'green' : 'zinc'">{{ $user->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $user->created_at?->format('M j, Y') }}</td>
                        <td class="px-4 py-3"><flux:button size="sm" :href="route('admin.users.edit', $user)" :aria-label="'Edit '.$user->name" wire:navigate>Edit</flux:button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {{ $this->users->links() }}
</section>
