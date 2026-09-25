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
                    {{ __('Mailbox providers email DMARC aggregate (and sometimes forensic) reports as attachments to the address published in your DMARC record\'s rua/ruf tags. The app can pick that mail up in three ways: it periodically logs into a mailbox you configure, it imports mail files (.eml / .msg) dropped in a local folder, or it accepts the mail directly over SMTP from a relay you control. Whichever way a message arrives, its report attachments are parsed and the message is filed away.') }}
                </p>
                <p>{{ __('Two kinds of mailbox connection are supported, configured separately (see Local files and SMTP listener below for the other two):') }}</p>
                <ul class="list-disc list-inside space-y-1">
                    <li>{{ __('IMAP Accounts — a plain IMAP mailbox (host, port, encryption, username/password).') }}</li>
                    <li>{{ __('Microsoft 365 Mailboxes — connects via the Microsoft Graph API rather than a mailbox password, either with "Connect with Microsoft" or with your own app registration (tenant ID, client ID/secret).') }}</li>
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
                <h3 id="connect-with-microsoft" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Connect with Microsoft') }}</h3>
                <p>
                    {{ __('The quickest way to add a Microsoft 365 mailbox or sending account. A tenant admin clicks "Connect with Microsoft", signs in and accepts the permissions; the tenant ID is then filled in and only the mailbox address is left to enter. Shared mailboxes work, and the admin who signs in does not need a mailbox of their own.') }}
                </p>
                <ul class="list-disc list-inside space-y-1">
                    <li>{{ __('One-time setup per installation — whoever runs the installation registers one multi-tenant app in Microsoft Entra with the Mail.ReadWrite and Mail.Send application permissions, adds this installation\'s callback address (shown on the Microsoft 365 pages) as a Web redirect URI, and sets MICROSOFT365_CLIENT_ID and MICROSOFT365_CLIENT_SECRET. Until then the button is hidden.') }}</li>
                    <li>{{ __('Once per tenant — the sign-in needs a Global Administrator or Privileged Role Administrator. Further mailboxes in the same tenant only need the tenant ID; no second sign-in.') }}</li>
                    <li>{{ __('Scope — like any app-only permission, the consent covers every mailbox in the tenant. To limit it to the DMARC mailbox, add an Exchange Online application access policy for the app (the command is shown on the Microsoft 365 pages).') }}</li>
                    <li>{{ __('Own app registration — still supported, for tenants that prefer to manage their own app and secret.') }}</li>
                </ul>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="local-files-and-smtp" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Local files and SMTP listener') }}</h3>
                <p>
                    {{ __('These are configured on the server (environment settings and console commands), not on a page in this app, so they are for whoever runs the installation.') }}
                </p>
                <ul class="list-disc list-inside space-y-2">
                    <li>{{ __('Local files — the dmarc:import-mail-files command imports .eml and .msg files (aggregate and forensic reports) from the inbox folder, or from files/folders you pass to it, and runs automatically every five minutes. Handled files are moved to processed or failed subfolders and cleaned up after the data retention period.') }}</li>
                    <li>{{ __('SMTP listener — the dmarc:smtp-serve command runs a small SMTP server that accepts mail from an internal relay or forwarder and imports it immediately. It runs continuously, so it needs a process manager (such as supervisor or systemd) to keep it up. It never relays mail onward.') }}</li>
                    <li>{{ __('Who may connect — only addresses listed in DMARC_SMTP_ALLOWED_IPS (IP addresses or CIDR ranges, comma-separated; loopback only when empty). An entry like spf:spf.protection.outlook.com follows that domain\'s SPF record, which suits providers such as Exchange Online whose addresses change; it is refreshed hourly. These ranges are shared by other customers of the provider, so on its own this does not prove the mail is yours.') }}</li>
                    <li>{{ __('Limits — messages above DMARC_SMTP_MAX_MESSAGE_BYTES are refused, and there is no login on the listener, so keep it on a private network.') }}</li>
                    <li>{{ __('Encryption (optional) — set DMARC_SMTP_TLS_CERT (a PEM certificate file, plus DMARC_SMTP_TLS_KEY if the key is in a separate file) and the listener offers STARTTLS. Add DMARC_SMTP_TLS_REQUIRED=true to refuse mail that is not sent over TLS, which is what a mail connector that insists on TLS (such as Exchange Online) needs; the connector must connect using a hostname that matches the certificate.') }}</li>
                </ul>
                <p>
                    {{ __('Mail that contains no recognizable DMARC report is still accepted by the listener but ends up in the failed folder.') }}
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="sending-account" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Microsoft 365 Sending Account') }}</h3>
                <p>
                    {{ __('This is a separate, unrelated setting: a Microsoft 365 account used only to send outbound mail from this application itself — currently alert notification emails (see Alert Rules & Events). It does not read or ingest anything; it is the opposite direction of the Microsoft 365 Mailboxes above.') }}
                </p>
            </div>

            <x-help.topic-nav current="mail-ingestion" />
        </div>
    </div>
</div>
