<?php

use App\Http\Controllers\DashboardSourcesExportController;
use App\Http\Controllers\ForensicReportDownloadController;
use App\Http\Controllers\ReportDownloadController;
use App\Http\Controllers\ReportsExportController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/dashboard');

Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('dashboard/export-sources', DashboardSourcesExportController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard.export-sources');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('reports', 'reports.index')->name('reports.index');
    Route::get('reports/export', ReportsExportController::class)->name('reports.export');
    Route::get('reports/{report}/download', ReportDownloadController::class)->name('reports.download');
    Volt::route('reports/{report}', 'reports.show')->name('reports.show');

    Volt::route('forensic-reports', 'forensic-reports.index')->name('forensic-reports.index');
    Route::get('forensic-reports/{forensicReport}/download', ForensicReportDownloadController::class)->name('forensic-reports.download');
    Volt::route('forensic-reports/{forensicReport}', 'forensic-reports.show')->name('forensic-reports.show');
});

Route::middleware(['auth', 'verified', 'role:admin,editor'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('organisations', 'admin.organisations')->name('organisations');
    Volt::route('domains', 'admin.domains')->name('domains');
    Volt::route('geoip', 'admin.geoip')->name('geoip');
    Volt::route('alert-rules', 'admin.alert-rules')->name('alert-rules');
    Volt::route('alert-events', 'admin.alert-events')->name('alert-events');
});

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Volt::route('users', 'admin.users')->name('users');
    Volt::route('domains/trash', 'admin.domains-trash')->name('domains.trash');
    Volt::route('imap-accounts', 'admin.imap-accounts')->name('imap-accounts');
    Volt::route('microsoft365-mailboxes', 'admin.microsoft365-mail-accounts')->name('microsoft365-mail-accounts');
    Volt::route('microsoft365-sending', 'admin.microsoft365-send-account')->name('microsoft365-send-account');
    Volt::route('scheduled-tasks', 'admin.scheduled-tasks')->name('scheduled-tasks');
    Volt::route('audit-log', 'admin.audit-log')->name('audit-log');
});

Route::middleware(['auth', 'verified'])->prefix('help')->name('help.')->group(function () {
    Volt::route('/', 'help.index')->name('index');
    Volt::route('organisations-and-domains', 'help.organisations-and-domains')->name('organisations-and-domains');
    Volt::route('dns-authentication', 'help.dns-authentication')->name('dns-authentication');
    Volt::route('mail-ingestion', 'help.mail-ingestion')->name('mail-ingestion');
    Volt::route('reports', 'help.reports')->name('reports');
    Volt::route('alerts', 'help.alerts')->name('alerts');
    Volt::route('dashboard', 'help.dashboard')->name('dashboard');
    Volt::route('users-and-roles', 'help.users-and-roles')->name('users-and-roles');
});

require __DIR__.'/auth.php';
