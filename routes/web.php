<?php

use App\Http\Controllers\ReportDownloadController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/dashboard');

Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('reports', 'reports.index')->name('reports.index');
    Route::get('reports/{report}/download', ReportDownloadController::class)->name('reports.download');
    Volt::route('reports/{report}', 'reports.show')->name('reports.show');
});

Route::middleware(['auth', 'verified', 'role:admin,editor'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('organisations', 'admin.organisations')->name('organisations');
    Volt::route('domains', 'admin.domains')->name('domains');
    Volt::route('imap-accounts', 'admin.imap-accounts')->name('imap-accounts');
    Volt::route('geoip', 'admin.geoip')->name('geoip');
    Volt::route('alert-rules', 'admin.alert-rules')->name('alert-rules');
    Volt::route('alert-events', 'admin.alert-events')->name('alert-events');
});

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('users', 'admin.users')->name('users');
});

require __DIR__.'/auth.php';
