<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    //
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Help: Dashboard') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('help.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Help') }}
            </a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="what-it-shows" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('What it shows') }}</h3>
                <p>
                    {{ __('The Dashboard summarizes DMARC pass/fail volume from ingested aggregate reports over a chosen date range, filterable by organisation and domain. It answers "is mail claiming to be from us passing authentication, and where is it coming from" at a glance, before you drill into individual reports.') }}
                </p>
                <p>
                    {{ __('The trend chart tracks pass/fail volume over time so a sudden change (a new sending source going unauthenticated, or an authentication regression) stands out. The sending-sources breakdown lists the source IPs/organisations behind the volume, enriched with country and ASN/organisation info where available.') }}
                </p>
                <p>
                    {{ __('The filters (organisation, domain and period) are kept in the address bar, so a filtered dashboard can be bookmarked or shared.') }}
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="overview" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Overview') }}</h3>
                <p>
                    {{ __('The Overview page shows one row per organisation: its domains, message volume, DMARC pass rate, the mix of published policies, how many domains need attention, when the last report arrived and open alerts. Organisations with problems are listed first.') }}
                </p>
                <p>
                    {{ __('Filter by name, period (7, 30 or 90 days) or "Only needing attention", and expand an organisation to see its domains with their flags (see Needs attention on the Organisations & Domains page). "Open dashboard" opens the Dashboard already filtered to that organisation.') }}
                </p>
            </div>

            <x-help.topic-nav current="dashboard" />
        </div>
    </div>
</div>
