<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('organisations', 'admin.organisations')->name('organisations');
    Volt::route('domains', 'admin.domains')->name('domains');
    Volt::route('imap-accounts', 'admin.imap-accounts')->name('imap-accounts');
    Volt::route('geoip', 'admin.geoip')->name('geoip');
    Volt::route('alert-rules', 'admin.alert-rules')->name('alert-rules');
    Volt::route('alert-events', 'admin.alert-events')->name('alert-events');
});

require __DIR__.'/auth.php';
