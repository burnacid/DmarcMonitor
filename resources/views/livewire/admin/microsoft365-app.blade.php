<?php

use App\Models\Microsoft365AppRegistration;
use App\Models\Microsoft365MailAccount;
use App\Models\Microsoft365SendAccount;
use App\Support\AuditLogger;
use App\Support\Microsoft365App;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $client_id = '';
    public string $client_secret = '';

    public ?string $status = null;

    public function mount(): void
    {
        $this->client_id = (string) Microsoft365AppRegistration::current()?->client_id;
    }

    public function save(): void
    {
        $registration = Microsoft365AppRegistration::current();

        $validated = $this->validate([
            'client_id' => 'required|uuid',
            'client_secret' => ($registration === null ? 'required' : 'nullable').'|string|max:1024',
        ], attributes: [
            'client_id' => __('application (client) ID'),
            'client_secret' => __('client secret'),
        ]);

        if (blank($validated['client_secret'])) {
            unset($validated['client_secret']);
        }

        $registration ??= new Microsoft365AppRegistration;
        $wasNew = ! $registration->exists;
        $registration->fill($validated)->save();

        AuditLogger::record(
            action: $wasNew ? 'microsoft365_app.created' : 'microsoft365_app.updated',
            description: ($wasNew ? 'Registered' : 'Updated').' the Microsoft 365 app '.$registration->client_id,
            subject: $registration,
            context: $wasNew ? null : AuditLogger::describeChanges($registration),
        );

        $this->client_secret = '';
        $this->status = __('Saved.');
    }

    public function delete(): void
    {
        $registration = Microsoft365AppRegistration::current();

        if ($registration === null) {
            return;
        }

        AuditLogger::record(
            action: 'microsoft365_app.deleted',
            description: 'Removed the Microsoft 365 app '.$registration->client_id,
            context: ['client_id' => $registration->client_id],
        );

        $registration->delete();

        $this->reset(['client_id', 'client_secret']);
        $this->status = __('Removed.');
    }

    public function with(): array
    {
        return [
            'registered' => Microsoft365AppRegistration::current() !== null,
            'redirectUri' => Microsoft365App::redirectUri(),
            'sharedAppAccountCount' => Microsoft365MailAccount::whereNull('client_id')->count()
                + Microsoft365SendAccount::whereNull('client_id')->count(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Microsoft 365 App') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-sm text-gray-700 dark:text-gray-300">
                <p>{{ __('This multi-tenant Microsoft Entra app registration powers "Connect with Microsoft" on the Microsoft 365 Mailboxes and Sending Account pages. Register it once; every tenant can then be connected with a single admin sign-in.') }}</p>
                <ol class="mt-3 list-decimal list-inside space-y-2">
                    <li>{{ __('In the Microsoft Entra admin center, go to App registrations → New registration. Under Supported account types, choose "Accounts in any organizational directory (Multitenant)".') }}</li>
                    <li>{{ __('Under Redirect URI, choose platform "Web" and enter:') }} <code class="px-1 rounded bg-gray-100 dark:bg-gray-900 break-all">{{ $redirectUri }}</code></li>
                    <li>{{ __('Go to API permissions → Add a permission → Microsoft Graph → Application permissions, and add Mail.ReadWrite and Mail.Send (one app serves both collecting and sending).') }}</li>
                    <li>{{ __('Go to Certificates & secrets → New client secret, and copy the value immediately — it is only shown once.') }}</li>
                    <li>{{ __('Enter the Application (client) ID and the secret below.') }}</li>
                </ol>
            </div>

            <form wire:submit="save" class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('App registration') }}</h3>
                    @if ($registered)
                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Configured') }}</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Not configured') }}</span>
                    @endif
                </div>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="app_client_id" :value="__('Application (client) ID')" />
                        <x-text-input wire:model="client_id" id="app_client_id" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="app_client_secret" :value="__('Client secret')" />
                        <x-text-input wire:model="client_secret" id="app_client_secret" type="password" autocomplete="new-password" :placeholder="$registered ? __('Leave blank to keep current') : ''" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('client_secret')" class="mt-2" />
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
                    @if ($status)
                        <span class="text-sm text-green-700 dark:text-green-400">{{ $status }}</span>
                    @endif
                    @if ($registered)
                        <x-danger-button type="button" wire:click="delete" wire:confirm="{{ $sharedAppAccountCount > 0 ? __(':count account(s) use Connect with Microsoft and will stop working. Remove the app registration?', ['count' => $sharedAppAccountCount]) : __('Remove the app registration?') }}">{{ __('Remove') }}</x-danger-button>
                    @endif
                    <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</div>
