<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    //
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Help: Aggregate & Forensic Reports') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('help.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Help') }}
            </a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="aggregate-reports" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Aggregate reports (RUA)') }}</h3>
                <p>
                    {{ __('Sent roughly daily by mailbox providers, an aggregate report summarizes every message they saw claiming to be from your domain over a time window: source IP, how many messages, what disposition was applied (none/quarantine/reject), and whether SPF and DKIM passed. The Reports page lists these, and opening one shows every underlying record plus the policy the sender evaluated against (p, sp, pct, adkim, aspf).') }}
                </p>
                <p>{{ __('Each source IP is enriched with reverse DNS, ASN/organisation and country where possible, to help identify who a sending source actually is.') }}</p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="forensic-reports" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Forensic reports (RUF)') }}</h3>
                <p>
                    {{ __('Sent by a smaller number of providers, a forensic report is generated for an individual failing message, with more detail (subject, envelope/header addresses, authentication results) but no volume — one report per incident, sent close to real time rather than as a daily digest. Not every provider sends these, so it\'s normal to see far fewer forensic than aggregate reports.') }}
                </p>
            </div>

            <x-help.topic-nav current="reports" />
        </div>
    </div>
</div>
