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
            </div>

            <x-help.topic-nav current="organisations-and-domains" />
        </div>
    </div>
</div>
