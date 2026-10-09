<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    //
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Help: Organisations & Domains') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('help.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Help') }}
            </a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="organisations" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Organisations') }}</h3>
                <p>
                    {{ __('An Organisation is a grouping of domains — typically a client or business unit — used to keep reporting, users and settings separated when this app monitors domains for more than one party. Every domain belongs to exactly one organisation, or none ("Unassigned").') }}
                </p>
                <p>
                    {{ __('The Organisations page can be searched by name or notes. The domain count links to that organisation\'s domains, "Add domains" opens bulk add with the organisation already chosen, and "PDF Report" downloads a report for a period (this month, last month, the last 30 or 90 days, a specific month or your own dates) with message volume, DMARC/SPF/DKIM pass rates, why DMARC fails, a daily trend and the top sending sources.') }}
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="domains" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Domains') }}</h3>
                <p>{{ __('A Domain is what this app actually monitors. Adding one starts DMARC/SPF/DKIM authentication tracking and lets incoming aggregate and forensic reports for that domain be matched up and shown in Reports.') }}</p>
                <ul class="list-disc list-inside space-y-1">
                    <li>{{ __('Domain (FQDN) — the domain name exactly as it appears in DMARC policy and report data, e.g. example.com.') }}</li>
                    <li>{{ __('Organisation — optional grouping; leave unassigned if you only monitor your own domains.') }}</li>
                    <li>{{ __('Active — inactive domains are kept for history but excluded from active monitoring.') }}</li>
                    <li>{{ __('Notes — free text for anything worth remembering about this domain.') }}</li>
                </ul>
                <p>
                    {{ __('The Recheck button re-runs a live DNS lookup for the DMARC, SPF and DKIM records and updates the status badges immediately (see the DMARC, SPF & DKIM help page for what each badge means). Expanding a row (the arrow next to the domain name) shows the full raw DNS records and the list of DKIM selectors actually seen in reports for that domain.') }}
                </p>
                <p>
                    {{ __('"Bulk add" adds many domains at once: paste them one per line or separated by commas or spaces, and pick an organisation. Pasted URLs, wildcards and trailing dots are tidied up, domains that already exist are skipped, and you get a summary of what was added, what already existed, what is in the trash and what was not a valid domain. Tick "Check DNS records now" to run the DNS check straight away.') }}
                </p>
                <p>
                    {{ __('The Domains page can be searched by name and filtered by organisation (including Unassigned) or to domains that need attention. Filters are kept in the address bar, so a filtered view can be bookmarked or shared.') }}
                </p>
                <p>
                    {{ __('Deleting a domain moves it to the Trash (admins only), which hides its reports until it is restored. Domains stay in the trash for the data retention period and are then deleted permanently.') }}
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="needs-attention" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Needs attention') }}</h3>
                <p>{{ __('Active domains are flagged under their name on the Domains and Overview pages when something needs looking at:') }}</p>
                <ul class="list-disc list-inside space-y-1">
                    <li>{{ __('No recent reports — no report has arrived for :days days (newly added domains get :grace days\' grace).', ['days' => \App\Services\Analytics\DomainHealthService::NO_REPORTS_DAYS, 'grace' => \App\Services\Analytics\DomainHealthService::NEW_DOMAIN_GRACE_DAYS]) }}</li>
                    <li>{{ __('DMARC missing, SPF missing, DKIM missing — the record was not found at the last DNS check.') }}</li>
                    <li>{{ __('Low pass rate — the DMARC pass rate is below :pct% (only with at least :messages messages in the period).', ['pct' => \App\Services\Analytics\DomainHealthService::PASS_GOOD, 'messages' => \App\Services\Analytics\DomainHealthService::MIN_VOLUME_FOR_PASS_RATE]) }}</li>
                    <li>{{ __('Open alerts — the domain has alert events that are not resolved yet.') }}</li>
                    <li>{{ __('Reports sent elsewhere — the domain\'s DMARC record does not send aggregate reports to this app\'s address (DMARC_RUA_ADDRESS) or to any active ingestion mailbox.') }}</li>
                    <li>{{ __('Report authorisation missing — reports go to an address on another domain that has not published the authorisation record (see Moving to enforcement on the DMARC, SPF & DKIM page).') }}</li>
                </ul>
                <p>{{ __('Some domains only send mail now and then. When reports arrived on fewer than :share% of the last :lookback days (with at least :history days of history), a quiet spell is shown as a grey "Rarely sends mail" remark instead of "No recent reports", and the domain does not count as needing attention. Likewise, a domain whose DMARC record sends reports here but has never received one is shown as "No mail seen".',['share' => (int) (\App\Services\Analytics\DomainHealthService::RARELY_SENDS_MAX_DAY_SHARE * 100), 'lookback' => \App\Services\Analytics\DomainHealthService::RARELY_SENDS_LOOKBACK_DAYS, 'history' => \App\Services\Analytics\DomainHealthService::RARELY_SENDS_MIN_HISTORY_DAYS]) }}</p>
            </div>

            <x-help.topic-nav current="organisations-and-domains" />
        </div>
    </div>
</div>
