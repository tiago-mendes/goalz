<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body data-goalz-theme="sage" data-goalz-shell class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-[var(--goalz-shell-border)] bg-[var(--goalz-shell-surface)]">
            <flux:sidebar.header>
                <a href="{{ route('home') }}" aria-label="Goalz home" class="px-2 text-xl font-bold tracking-tight text-[var(--goalz-shell-wordmark)] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-current" wire:navigate>Goalz</a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="tag" :href="route('expense-categories.index')" :current="request()->routeIs('expense-categories.*')" wire:navigate>Categories</flux:sidebar.item>
                    <flux:sidebar.item icon="banknotes" :href="route('monthly-income.index')" :current="request()->routeIs('monthly-income.*')" wire:navigate>Income</flux:sidebar.item>
                    <flux:sidebar.item icon="calendar-days" :href="route('fixed-expenses.index')" :current="request()->routeIs('fixed-expenses.*')" wire:navigate>Fixed Expenses</flux:sidebar.item>
                    <flux:sidebar.item icon="banknotes" :href="route('expenses.index')" :current="request()->routeIs('expenses.*')" wire:navigate>Expenses</flux:sidebar.item>
                    <flux:sidebar.item icon="building-library" :href="route('accounts.index')" :current="request()->routeIs('accounts.*')" wire:navigate>Accounts</flux:sidebar.item>
                    @can('manage-users')
                        <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.*')" wire:navigate>Manage Users</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-[var(--goalz-shell-border)] bg-[var(--goalz-shell-surface)] lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
