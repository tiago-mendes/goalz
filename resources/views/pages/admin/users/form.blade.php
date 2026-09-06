<?php

use App\Actions\ProvisionExpenseCategories;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage user')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules;

    #[Locked]
    public ?int $userId = null;
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $role = 'user';
    public $is_active = true;
    public string $currency = 'BRL';

    public function boot(): void
    {
        Gate::authorize('manage-users');
    }

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $user = User::select(['id', 'name', 'email', 'role', 'is_active', 'currency'])->findOrFail($user->id);
            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->role = $user->role->value;
            $this->is_active = $user->is_active;
            $this->currency = $user->currency;
        }
    }

    public function save(): void
    {
        Gate::authorize('manage-users');

        $validated = $this->validate([
            ...$this->profileRules($this->userId),
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['required', 'boolean'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            ...($this->userId === null ? ['password' => $this->passwordRules()] : []),
        ]);

        DB::transaction(function () use ($validated): void {
            $activeAdmins = User::where('role', UserRole::Admin)->where('is_active', true)
                ->orderBy('id')->lockForUpdate()->get(['id']);
            Gate::authorize('manage-users');

            $user = $this->userId === null ? new User : User::lockForUpdate()->findOrFail($this->userId);

            if ($user->id === auth()->id()) {
                if ($validated['role'] !== UserRole::Admin->value) {
                    throw ValidationException::withMessages(['role' => 'You cannot demote your own account.']);
                }
                if (! $validated['is_active']) {
                    throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
                }
            }

            if ($user->exists && $activeAdmins->contains('id', $user->id) && $activeAdmins->count() === 1
                && ($validated['role'] !== UserRole::Admin->value || ! $validated['is_active'])) {
                throw ValidationException::withMessages(['role' => 'At least one active admin must remain.']);
            }

            $user->name = $validated['name'];
            $user->email = $validated['email'];
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->role = UserRole::from($validated['role']);
            $user->is_active = $validated['is_active'];
            $user->currency = $validated['currency'];
            if (! $user->exists) {
                $user->password = $validated['password'];
            }
            $user->save();
            if ($user->wasRecentlyCreated) {
                app(ProvisionExpenseCategories::class)->handle($user);
            }
        });

        $this->reset('password', 'password_confirmation');
        session()->flash('status', 'User saved.');
        $this->redirectRoute('admin.users.index', navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-2xl space-y-6">
    <flux:button :href="route('admin.users.index')" wire:navigate>Back to users</flux:button>
    <flux:heading size="xl" level="1">{{ $userId ? 'Edit user' : 'Create user' }}</flux:heading>
    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" required autocomplete="off" />
        <flux:input wire:model="email" label="Email" type="email" required autocomplete="off" />
        @if ($userId === null)
            <flux:input wire:model="password" label="Password" type="password" required autocomplete="new-password" />
            <flux:input wire:model="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        @endif
        <flux:select wire:model="role" label="Role" :disabled="$userId === auth()->id()">
            <flux:select.option value="user">User</flux:select.option>
            <flux:select.option value="admin">Admin</flux:select.option>
        </flux:select>
        <flux:checkbox wire:model="is_active" label="Active account" :disabled="$userId === auth()->id()" />
        @if ($userId === auth()->id())
            <flux:text>Your own account must remain active and retain the admin role.</flux:text>
        @endif
        <flux:input wire:model="currency" label="Currency" placeholder="BRL" maxlength="3" required />
        <flux:modal.trigger name="confirm-save">
            <flux:button variant="primary">{{ $userId ? 'Review changes' : 'Create user' }}</flux:button>
        </flux:modal.trigger>
        <flux:modal name="confirm-save" class="md:w-96">
            <div class="space-y-6">
                <flux:heading size="lg">Save this account?</flux:heading>
                <flux:text>Role and active status determine access to the application. Check these choices before saving.</flux:text>
                <div class="flex gap-3">
                    <flux:modal.close><flux:button>Cancel</flux:button></flux:modal.close>
                    <flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save user</flux:button></flux:modal.close>
                </div>
            </div>
        </flux:modal>
    </form>
</section>
