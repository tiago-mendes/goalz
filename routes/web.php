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
    Route::livewire('expense-categories', 'pages::expense-categories.index')->name('expense-categories.index');
    Route::livewire('expense-categories/create', 'pages::expense-categories.form')->name('expense-categories.create');
    Route::livewire('expense-categories/{categoryId}/edit', 'pages::expense-categories.form')->whereNumber('categoryId')->name('expense-categories.edit');
});

Route::middleware(['auth', 'can:manage-users'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::redirect('/', '/admin/users')->name('index');
    Route::livewire('users', 'pages::admin.users.index')->name('users.index');
    Route::livewire('users/create', 'pages::admin.users.form')->name('users.create');
    Route::livewire('users/{user}/edit', 'pages::admin.users.form')->name('users.edit');
});

require __DIR__.'/settings.php';
