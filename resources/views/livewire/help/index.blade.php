<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    /**
     * @return array<int, array{topic: string, heading: string, url: string, excerpt: string}>
     */
    public function with(): array
    {
        return [
            'searchIndex' => [
                [
                    'topic' => __('Organisations & Domains'),
                    'heading' => __('Organisations'),
                    'url' => route('help.organisations-and-domains').'#organisations',
                    'excerpt' => __('A grouping of domains — typically a client or business unit — used to keep reporting, users and settings separated.'),
                ],
                [
                    'topic' => __('Organisations & Domains'),
                    'heading' => __('Domains'),
                    'url' => route('help.organisations-and-domains').'#domains',
                    'excerpt' => __('What this app actually monitors: FQDN, organisation, active flag, notes, the Recheck button, and expanding a row for raw DNS records.'),
                ],
                [
                    'topic' => __('DMARC, SPF & DKIM'),
                    'heading' => __('What these mechanisms do'),
                    'url' => route('help.dns-authentication').'#what-these-mechanisms-do',
                    'excerpt' => __('SPF, DKIM and DMARC exist to stop other people from sending email that pretends to come from your domain.'),
                ],
                [
                    'topic' => __('DMARC, SPF & DKIM'),
                    'heading' => __('Status badges on the Domains page'),
                    'url' => route('help.dns-authentication').'#status-badges',
                    'excerpt' => __('What Valid, Weak, Missing, Unknown and Not checked mean for DMARC, SPF and DKIM.'),
                ],
                [
                    'topic' => __('DMARC, SPF & DKIM'),
                    'heading' => __('DKIM selectors, and the "Configured" badge'),
                    'url' => route('help.dns-authentication').'#dkim-selectors',
                    'excerpt' => __('Selectors seen in reports, what "Configured" means, and why unfamiliar selectors with only failures usually mean spoofed mail.'),
                ],
                [
                    'topic' => __('Mail Ingestion'),
                    'heading' => __('How reports get in'),
                    'url' => route('help.mail-ingestion').'#how-reports-get-in',
                    'excerpt' => __('IMAP Accounts and Microsoft 365 Mailboxes — how this app fetches DMARC report emails.'),
                ],
                [
                    'topic' => __('Mail Ingestion'),
                    'heading' => __('Common settings'),
                    'url' => route('help.mail-ingestion').'#common-settings',
                    'excerpt' => __('Inbox/Processed/Failed folders, mark as read, include read messages, delete after processing, last polled, last error.'),
                ],
                [
                    'topic' => __('Mail Ingestion'),
                    'heading' => __('Microsoft 365 Sending Account'),
                    'url' => route('help.mail-ingestion').'#sending-account',
                    'excerpt' => __('The separate app registration used only to send outbound alert emails.'),
                ],
                [
                    'topic' => __('Aggregate & Forensic Reports'),
                    'heading' => __('Aggregate reports (RUA)'),
                    'url' => route('help.reports').'#aggregate-reports',
                    'excerpt' => __('Daily summaries of mail volume, disposition, SPF/DKIM results per source IP, enriched with GeoIP.'),
                ],
                [
                    'topic' => __('Aggregate & Forensic Reports'),
                    'heading' => __('Forensic reports (RUF)'),
                    'url' => route('help.reports').'#forensic-reports',
                    'excerpt' => __('Per-message failure detail sent close to real time by a smaller number of providers.'),
                ],
                [
                    'topic' => __('Alert Rules & Events'),
                    'heading' => __('Alert Rules'),
                    'url' => route('help.alerts').'#alert-rules',
                    'excerpt' => __('Per-domain thresholds, lookback windows, and email/webhook notification channels.'),
                ],
                [
                    'topic' => __('Alert Rules & Events'),
                    'heading' => __('Alert Events'),
                    'url' => route('help.alerts').'#alert-events',
                    'excerpt' => __('Fired/resolved alert instances and the unresolved-count badge in the navigation bar.'),
                ],
                [
                    'topic' => __('Dashboard'),
                    'heading' => __('What it shows'),
                    'url' => route('help.dashboard').'#what-it-shows',
                    'excerpt' => __('Pass/fail volume, trend chart, and sending-source breakdown, filterable by organisation and domain.'),
                ],
                [
                    'topic' => __('Users & Roles'),
                    'heading' => __('Roles'),
                    'url' => route('help.users-and-roles').'#roles',
                    'excerpt' => __('What Admin, Editor and Viewer can each access.'),
                ],
                [
                    'topic' => __('Users & Roles'),
                    'heading' => __('Profile & passkeys'),
                    'url' => route('help.users-and-roles').'#profile-passkeys',
                    'excerpt' => __('Managing your own name, email, password, and passwordless sign-in.'),
                ],
            ],
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Help') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6" x-data="{
            query: '',
            entries: @js($searchIndex),
            get results() {
                const q = this.query.trim().toLowerCase();
                if (! q) {
                    return [];
                }
                return this.entries.filter(e =>
                    e.heading.toLowerCase().includes(q) ||
                    e.topic.toLowerCase().includes(q) ||
                    e.excerpt.toLowerCase().includes(q)
                );
            },
        }">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('This is a quick reference for how DmarcMonitor works: the concepts behind email authentication (DMARC, SPF, DKIM), how reports get into the system, and what each screen in this app does.') }}
                </p>

                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5A6.5 6.5 0 114 10.5a6.5 6.5 0 0113 0z" />
                    </svg>
                    <input
                        type="text"
                        x-model="query"
                        placeholder="{{ __('Search help topics…') }}"
                        class="w-full pl-9 border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                    >
                </div>
            </div>

            <template x-if="query.trim() !== ''">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                    <template x-if="results.length === 0">
                        <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('No help topics match your search.') }}</p>
                    </template>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700" x-show="results.length > 0">
                        <template x-for="result in results" :key="result.url">
                            <li class="py-3 first:pt-0 last:pb-0">
                                <a :href="result.url" wire:navigate class="block hover:text-indigo-600 dark:hover:text-indigo-400">
                                    <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500" x-text="result.topic"></div>
                                    <div class="mt-0.5 font-medium text-gray-900 dark:text-gray-100" x-text="result.heading"></div>
                                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400" x-text="result.excerpt"></p>
                                </a>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" x-show="query.trim() === ''">
                <a href="{{ route('help.organisations-and-domains') }}" wire:navigate class="block bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-indigo-500 transition">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Organisations & Domains') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('How domains are grouped, added, and kept active.') }}</p>
                </a>

                <a href="{{ route('help.dns-authentication') }}" wire:navigate class="block bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-indigo-500 transition">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('DMARC, SPF & DKIM') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('What each authentication mechanism does, and what the status badges mean.') }}</p>
                </a>

                <a href="{{ route('help.mail-ingestion') }}" wire:navigate class="block bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-indigo-500 transition">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Mail Ingestion') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('How report emails are fetched from IMAP or Microsoft 365 mailboxes.') }}</p>
                </a>

                <a href="{{ route('help.reports') }}" wire:navigate class="block bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-indigo-500 transition">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Aggregate & Forensic Reports') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('What DMARC reports contain and where to find them.') }}</p>
                </a>

                <a href="{{ route('help.alerts') }}" wire:navigate class="block bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-indigo-500 transition">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Alert Rules & Events') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('How to get notified when something needs attention.') }}</p>
                </a>

                <a href="{{ route('help.dashboard') }}" wire:navigate class="block bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-indigo-500 transition">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Dashboard') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Reading the metrics, trends, and filters.') }}</p>
                </a>

                <a href="{{ route('help.users-and-roles') }}" wire:navigate class="block bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-indigo-500 transition">
                    <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Users & Roles') }}</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('What admins and editors can each do.') }}</p>
                </a>
            </div>
        </div>
    </div>
</div>
