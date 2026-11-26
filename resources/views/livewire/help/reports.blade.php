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
                <p>{{ __('The Records tab on the Reports page lists the individual records of all reports instead, one row per source IP. The filters then apply to each record: with SPF set to Fail, only failing records are shown, not every record of a report that has one. The search looks through the source IP, hostname, ASN (e.g. AS13335) and ASN organisation, the header, envelope, DKIM and SPF domains, and the reporting organisation. "View report" opens the record\'s report, and Export CSV downloads exactly the listed records.') }}</p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="null-senders" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Null senders and expected sources') }}</h3>
                <p>
                    {{ __('Bounces, out-of-office replies and automatic notifications are sent with an empty envelope sender, shown as "<>". The receiver then checks SPF on the name the sending server introduced itself with (the HELO name, for example ipw.inkyphishfence.com) instead. That name never matches your From domain, so SPF can\'t align and only DKIM can make these messages pass DMARC. Such records get a "Null sender" label on the report, with the HELO name in its tooltip.') }}
                </p>
                <p>
                    {{ __('A typical cause is a mail security gateway, such as INKY, that sends notifications or passes on messages using your domain in the From address. The real fix is on the sender\'s side: have it DKIM-sign those messages for your domain, or send them from its own domain.') }}
                </p>
                <p>
                    {{ __('Until that\'s fixed, editors and admins can mark the source as expected under Settings > Expected Sources, or with "Mark as expected" next to a null sender record on a report. An expected source matches on the SPF/HELO domain (including its subdomains), the hostname (PTR), an IP address or range, or an ASN, and can be limited to null sender messages only. Its messages are labelled "Expected" and can be left out of alerts. By default they still count in the pass rates, because receivers still reject or quarantine them. Switch on "Hide from the dashboard and DMARC scores" to leave them out of the dashboard, its CSV export, the pass rates on the Domains and Overview pages, the wallboard and the PDF reports; the dashboard then says how many messages it hides. They always stay visible on the Reports pages.') }}
                </p>
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
