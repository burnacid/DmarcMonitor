<?php

use App\Models\ForensicReport;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ForensicReport $forensicReport;

    public function mount(ForensicReport $forensicReport): void
    {
        $forensicReport->loadMissing('domain');

        abort_unless(auth()->user()->canAccessOrganisation($forensicReport->domain?->organisation_id), 404);

        $this->forensicReport = $forensicReport->load('domain');
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
            {{ __('Forensic Report') }} &mdash; {{ $forensicReport->domain->fqdn }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('forensic-reports.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; {{ __('Back to forensic reports') }}</a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Domain') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $forensicReport->domain->fqdn }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Source IP') }}</dt>
                        <dd class="mt-1 font-mono text-gray-900 dark:text-gray-100">{{ $forensicReport->source_ip ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Delivery Result') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->delivery_result ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Header From') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->header_from ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Envelope From') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->envelope_from ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Envelope To') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->envelope_to ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('DKIM') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->dkim_domain ?? '—' }} ({{ $forensicReport->dkim_result ?? 'n/a' }})</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('SPF') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->spf_domain ?? '—' }} ({{ $forensicReport->spf_result ?? 'n/a' }})</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Original Envelope ID') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $forensicReport->original_envelope_id ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Arrival Date') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->arrival_date?->format('Y-m-d H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Processed at') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->processed_at?->diffForHumans() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Subject') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $forensicReport->subject ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Authentication Results') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-gray-900 dark:text-gray-100 whitespace-pre-wrap">{{ $forensicReport->authentication_results ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Raw Message') }}</dt>
                        <dd class="mt-1">
                            @if ($forensicReport->raw_message_path)
                                <a href="{{ route('forensic-reports.download', $forensicReport) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Download') }}</a>
                            @else
                                <span class="text-gray-400 dark:text-gray-500">{{ __('Not stored') }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
