<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    //
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Help: Mail Ingestion') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('help.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Help') }}
            </a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="how-reports-get-in" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('How reports get in') }}</h3>
                <p>
                    {{ __('Mailbox providers email DMARC aggregate (and sometimes forensic) reports as attachments to the address published in your DMARC record\'s rua/ruf tags. This app doesn\'t receive that mail directly — instead it periodically logs into a mailbox you configure, downloads and parses the report attachments, and files the messages away.') }}
                </p>
                <p>{{ __('Two kinds of mailbox connection are supported, configured separately:') }}</p>
                <ul class="list-disc list-inside space-y-1">
                    <li>{{ __('IMAP Accounts — a plain IMAP mailbox (host, port, encryption, username/password).') }}</li>
                    <li>{{ __('Microsoft 365 Mailboxes — connects via the Microsoft Graph API using an app registration (tenant ID, client ID/secret) rather than a mailbox password.') }}</li>
                </ul>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="common-settings" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Common settings') }}</h3>
                <ul class="list-disc list-inside space-y-1">
                    <li>{{ __('Inbox / Processed / Failed folders — where messages are read from, and where they\'re moved to once handled: Processed on success, Failed if the report couldn\'t be parsed.') }}</li>
                    <li>{{ __('Mark as read — whether processed messages are flagged as read in the mailbox.') }}</li>
                    <li>{{ __('Include read messages — whether to also process messages that were already marked read before this app saw them (useful for a first import).') }}</li>
                    <li>{{ __('Delete after processing — removes the message from the mailbox entirely instead of moving it to Processed/Failed.') }}</li>
                    <li>{{ __('Active — inactive accounts are skipped by the polling job.') }}</li>
                </ul>
                <p>
                    {{ __('Each account shows Last polled and, if something went wrong, Last error, so you can see at a glance whether ingestion for a mailbox is healthy.') }}
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="sending-account" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Microsoft 365 Sending Account') }}</h3>
                <p>
                    {{ __('This is a separate, unrelated setting: an app registration used only to send outbound mail from this application itself — currently alert notification emails (see Alert Rules & Events). It does not read or ingest anything; it is the opposite direction of the Microsoft 365 Mailboxes above.') }}
                </p>
            </div>

            <x-help.topic-nav current="mail-ingestion" />
        </div>
    </div>
</div>
