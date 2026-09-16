<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    //
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Help: DMARC, SPF & DKIM') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('help.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Help') }}
            </a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="what-these-mechanisms-do" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('What these mechanisms do') }}</h3>
                <p>
                    {{ __('SPF, DKIM and DMARC exist to stop other people from sending email that pretends to come from your domain. Mailbox providers check them on every message and use the result to decide whether to deliver it, flag it as spam, or reject it.') }}
                </p>

                <dl class="space-y-4">
                    <div>
                        <dt class="font-medium text-gray-900 dark:text-gray-100">SPF ({{ __('Sender Policy Framework') }})</dt>
                        <dd class="mt-1">{{ __('A DNS TXT record listing which mail servers are allowed to send email for your domain. The receiving server checks the IP address the message actually came from against this list.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-900 dark:text-gray-100">DKIM ({{ __('DomainKeys Identified Mail') }})</dt>
                        <dd class="mt-1">{{ __('Your mail server cryptographically signs outgoing messages. The public key needed to verify that signature is published in DNS at a "selector" address, e.g. selector._domainkey.yourdomain.com. The receiving server fetches that key and checks the signature.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-900 dark:text-gray-100">DMARC ({{ __('Domain-based Message Authentication, Reporting & Conformance') }})</dt>
                        <dd class="mt-1">{{ __('A policy record that tells receivers what to do when a message fails SPF and DKIM (none / quarantine / reject), and where to send aggregate and forensic reports about the mail they see claiming to be from your domain. This app exists to collect and make sense of those reports.') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="status-badges" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Status badges on the Domains page') }}</h3>
                <p>{{ __('Each domain shows a status badge for DMARC, SPF and DKIM, based on the most recent DNS check (use the "Recheck" button to refresh it):') }}</p>
                <ul class="space-y-2">
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200 shrink-0">{{ __('Valid') }}</span>
                        <span>{{ __('A record was found in DNS and it looks correctly configured.') }}</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200 shrink-0">{{ __('Weak') }}</span>
                        <span>{{ __('A record exists but is not very protective — most commonly a DMARC record with p=none, which only monitors and does not tell receivers to quarantine or reject failing mail.') }}</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 shrink-0">{{ __('Missing') }}</span>
                        <span>{{ __('No record was found in DNS for this mechanism.') }}</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200 shrink-0">{{ __('Unknown') }}</span>
                        <span>{{ __('For DKIM specifically: no selector has been seen yet, so we don\'t know where to look in DNS. Once an aggregate report mentions a selector for this domain, a DNS lookup for it becomes possible.') }}</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-400 dark:text-gray-500 shrink-0">{{ __('Not checked') }}</span>
                        <span>{{ __('This domain has never had a DNS check run.') }}</span>
                    </li>
                </ul>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="dkim-selectors" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('DKIM selectors, and the "Configured" badge') }}</h3>
                <p>
                    {{ __('Because a DKIM key lives at selector._domainkey.yourdomain.com, you first need to know which selector name to look up. Expand a domain on the Domains page to see a "Selectors seen in reports" list — every selector name that has actually shown up in aggregate reports for that domain, with pass/fail counts and when it was last seen.') }}
                </p>
                <p>
                    {{ __('The selector marked "Configured" is the one currently stored on the domain and used for the DNS status check above — normally the selector your own mail platform (e.g. Microsoft 365, Google Workspace) publishes and rotates automatically.') }}
                </p>
                <p>
                    {{ __('It is completely normal to see other, unfamiliar selectors in that list with 0 passes and every message failing. Spammers who forge your domain in the From header often invent a random-looking selector name (or reuse one from elsewhere); since it was never published in your DNS, DKIM fails for that mail. That is expected and does not indicate a problem with your own setup — it is exactly the kind of abuse DMARC reporting is meant to surface.') }}
                </p>
            </div>

            <x-help.topic-nav current="dns-authentication" />
        </div>
    </div>
</div>
