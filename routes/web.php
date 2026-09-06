<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'can:manage-users'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::redirect('/', '/admin/users')->name('index');
    Route::livewire('users', 'pages::admin.users.index')->name('users.index');
    Route::livewire('users/create', 'pages::admin.users.form')->name('users.create');
    Route::livewire('users/{user}/edit', 'pages::admin.users.form')->name('users.edit');
});

require __DIR__.'/settings.php';
