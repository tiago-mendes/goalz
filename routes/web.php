<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('expenses', 'pages::expenses.index')->name('expenses.index');
    Route::livewire('expenses/create', 'pages::expenses.form')->name('expenses.create');
    Route::livewire('expenses/{expenseId}/edit', 'pages::expenses.form')->whereNumber('expenseId')->name('expenses.edit');
    Route::livewire('fixed-expenses', 'pages::fixed-expenses.index')->name('fixed-expenses.index');
    Route::livewire('fixed-expenses/create', 'pages::fixed-expenses.form')->name('fixed-expenses.create');
    Route::livewire('fixed-expenses/{fixedExpenseId}/edit', 'pages::fixed-expenses.form')->whereNumber('fixedExpenseId')->name('fixed-expenses.edit');
    Route::livewire('monthly-income', 'pages::monthly-income.index')->name('monthly-income.index');
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('reports/cash-flow', 'pages::reports.cash-flow')->name('reports.cash-flow');
    Route::livewire('reports/assets', 'pages::reports.assets')->name('reports.assets');
    Route::livewire('expense-categories', 'pages::expense-categories.index')->name('expense-categories.index');
    Route::livewire('expense-categories/create', 'pages::expense-categories.form')->name('expense-categories.create');
    Route::livewire('expense-categories/{categoryId}/edit', 'pages::expense-categories.form')->whereNumber('categoryId')->name('expense-categories.edit');
    Route::livewire('accounts', 'pages::accounts.index')->name('accounts.index');
    Route::livewire('accounts/create', 'pages::accounts.form')->name('accounts.create');
    Route::livewire('accounts/{accountId}/history', 'pages::accounts.history')->whereNumber('accountId')->name('accounts.history');
    Route::livewire('accounts/{accountId}/edit', 'pages::accounts.form')->whereNumber('accountId')->name('accounts.edit');
    Route::livewire('credit-cards', 'pages::credit-cards.index')->name('credit-cards.index');
    Route::livewire('credit-cards/create', 'pages::credit-cards.form')->name('credit-cards.create');
    Route::livewire('credit-cards/{creditCardId}', 'pages::credit-cards.show')->whereNumber('creditCardId')->name('credit-cards.show');
    Route::livewire('credit-cards/{creditCardId}/edit', 'pages::credit-cards.form')->whereNumber('creditCardId')->name('credit-cards.edit');
    Route::livewire('goals', 'pages::goals.index')->name('goals.index');
    Route::livewire('goals/create', 'pages::goals.form')->name('goals.create');
    Route::livewire('goals/{goalId}/edit', 'pages::goals.form')->whereNumber('goalId')->name('goals.edit');
    Route::livewire('goals/{goalId}/allocations', 'pages::goals.allocations')->whereNumber('goalId')->name('goals.allocations');
});

Route::middleware(['auth', 'can:manage-users'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::redirect('/', '/admin/users')->name('index');
    Route::livewire('users', 'pages::admin.users.index')->name('users.index');
    Route::livewire('users/create', 'pages::admin.users.form')->name('users.create');
    Route::livewire('users/{user}/edit', 'pages::admin.users.form')->name('users.edit');
});

require __DIR__.'/settings.php';
