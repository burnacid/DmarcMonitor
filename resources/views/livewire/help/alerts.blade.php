<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    //
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Help: Alert Rules & Events') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('help.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                &larr; {{ __('Back to Help') }}
            </a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="alert-rules" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Alert Rules') }}</h3>
                <p>
                    {{ __('An Alert Rule watches one organisation (or every domain, if left unscoped) for a condition — for example, DMARC pass rate dropping below a threshold percentage over a lookback window — and fires per domain when it\'s met. Rules can notify by email and/or webhook, to the addresses/URL you configure.') }}
                </p>
                <p>
                    {{ __('Rules are re-evaluated every 15 minutes. The lookback window counts reports by when we received them, not the period they cover — providers often send reports a day or more after the traffic they describe, so a window based on the covered period would permanently miss anything that arrives late.') }}
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
                <h3 id="alert-events" class="text-base font-medium text-gray-900 dark:text-gray-100">{{ __('Alert Events') }}</h3>
                <p>
                    {{ __('Each time a rule\'s condition is met, an Alert Event is created recording when it fired and the details that triggered it. An event stays open until the underlying condition clears, at which point it\'s marked resolved. The red counter badge next to Alerts in the navigation bar counts currently unresolved events.') }}
                </p>
                <p>{{ __('Outbound alert emails are sent using the Microsoft 365 Sending Account (see Mail Ingestion) rather than the report-ingestion mailboxes.') }}</p>
            </div>

            <x-help.topic-nav current="alerts" />
        </div>
    </div>
</div>
