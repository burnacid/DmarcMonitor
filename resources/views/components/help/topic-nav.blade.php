@props(['current' => null])

@php
    $topics = [
        'organisations-and-domains' => __('Organisations & Domains'),
        'dns-authentication' => __('DMARC, SPF & DKIM'),
        'mail-ingestion' => __('Mail Ingestion'),
        'reports' => __('Aggregate & Forensic Reports'),
        'alerts' => __('Alert Rules & Events'),
        'dashboard' => __('Dashboard'),
        'users-and-roles' => __('Users & Roles'),
    ];
@endphp

<div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
    <h3 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">{{ __('Other help topics') }}</h3>
    <ul class="space-y-1.5 text-sm">
        @foreach ($topics as $slug => $label)
            @unless ($slug === $current)
                <li>
                    <a href="{{ route('help.'.$slug) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                        {{ $label }}
                    </a>
                </li>
            @endunless
        @endforeach
    </ul>
</div>
