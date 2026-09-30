<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    //
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Help: Users & Roles') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('help.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Help') }}
            </a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="roles" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Roles') }}</h3>
                <dl class="space-y-4">
                    <div>
                        <dt class="font-medium text-gray-900 dark:text-gray-100">{{ __('Admin') }}</dt>
                        <dd class="mt-1">{{ __('Full access, including everything below plus Users management, IMAP Accounts, Microsoft 365 Mailboxes, the Microsoft 365 Sending Account, the Microsoft 365 App settings, the domain Trash, Scheduled Tasks and the Audit Log.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-900 dark:text-gray-100">{{ __('Editor') }}</dt>
                        <dd class="mt-1">{{ __('Can view everything a viewer can, plus manage Organisations (including their PDF reports), Domains, GeoIP settings, Alert Rules and Alert Events. Mail ingestion accounts and user management are admin-only.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-900 dark:text-gray-100">{{ __('Viewer') }}</dt>
                        <dd class="mt-1">{{ __('Read-only: Overview, Dashboard, Reports, Forensic Reports and this Help section. Cannot add or edit domains, accounts, or alert rules.') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="profile-passkeys" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Profile, passkeys & two-factor') }}</h3>
                <p>
                    {{ __('Every user manages their own name, email and password from the Profile page, and can register a passkey there for passwordless sign-in.') }}
                </p>
                <p>
                    {{ __('Two-factor authentication can also be turned on from the Profile page: scan the QR code with an authenticator app and confirm with a code. After that, signing in with a password also asks for a code. Store the recovery codes somewhere safe — each one works once in place of the app if you lose access to it, and they can be regenerated from the Profile page.') }}
                </p>
            </div>

            <x-help.topic-nav current="users-and-roles" />
        </div>
    </div>
</div>
