<?php

use App\Models\AggregateReport;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public AggregateReport $report;

    #[Url]
    public string $spf_result = '';

    #[Url]
    public string $dkim_result = '';

    #[Url]
    public string $disposition = '';

    public function mount(AggregateReport $report): void
    {
        $this->report = $report->load(['domain', 'records']);
    }

    public function clearFilters(): void
    {
        $this->reset(['spf_result', 'dkim_result', 'disposition']);
    }

    public function with(): array
    {
        return [
            'filteredRecords' => $this->report->records
                ->when($this->spf_result, fn (Collection $records) => $records->where('spf_result', $this->spf_result))
                ->when($this->dkim_result, fn (Collection $records) => $records->where('dkim_result', $this->dkim_result))
                ->when($this->disposition, fn (Collection $records) => $records->where('disposition', $this->disposition)),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">
            {{ __('Report') }} &mdash; {{ $report->org_name }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <a href="{{ route('reports.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; {{ __('Back to reports') }}</a>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Domain') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $report->domain->fqdn }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Reporting org') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $report->org_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Contact email') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $report->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Report ID') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $report->report_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Date range') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $report->date_range_begin->format('Y-m-d H:i') }} &rarr; {{ $report->date_range_end->format('Y-m-d H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Processed at') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $report->processed_at?->diffForHumans() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Policy') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">
                            p={{ $report->policy_p }}, sp={{ $report->policy_sp ?? '—' }}, adkim={{ $report->policy_adkim }}, aspf={{ $report->policy_aspf }}, pct={{ $report->policy_pct }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Raw XML') }}</dt>
                        <dd class="mt-1">
                            @if ($report->raw_xml_path)
                                <a href="{{ route('reports.download', $report) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Download') }}</a>
                            @else
                                <span class="text-gray-400 dark:text-gray-500">{{ __('Not stored') }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="flex flex-wrap items-end justify-between gap-3 px-6 pt-4">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Records') }}</h3>

                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <x-input-label for="spf_result" :value="__('SPF')" />
                            <select wire:model.live="spf_result" id="spf_result" class="mt-1 border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                <option value="">{{ __('Any') }}</option>
                                <option value="pass">{{ __('Pass') }}</option>
                                <option value="fail">{{ __('Fail') }}</option>
                            </select>
                        </div>

                        <div>
                            <x-input-label for="dkim_result" :value="__('DKIM')" />
                            <select wire:model.live="dkim_result" id="dkim_result" class="mt-1 border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                <option value="">{{ __('Any') }}</option>
                                <option value="pass">{{ __('Pass') }}</option>
                                <option value="fail">{{ __('Fail') }}</option>
                            </select>
                        </div>

                        <div>
                            <x-input-label for="disposition" :value="__('Disposition')" />
                            <select wire:model.live="disposition" id="disposition" class="mt-1 border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                <option value="">{{ __('Any') }}</option>
                                <option value="none">{{ __('None') }}</option>
                                <option value="quarantine">{{ __('Quarantine') }}</option>
                                <option value="reject">{{ __('Reject') }}</option>
                            </select>
                        </div>

                        @if ($spf_result || $dkim_result || $disposition)
                            <x-secondary-button wire:click="clearFilters">{{ __('Clear filters') }}</x-secondary-button>
                        @endif
                    </div>
                </div>
                <div class="md:overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 mt-2 max-md:block max-md:mt-4">
                        <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Source IP') }}</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Count') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Disposition') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('DKIM') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('SPF') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Header From') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Envelope From') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Envelope To') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                            @forelse ($filteredRecords as $record)
                                <tr wire:key="record-{{ $record->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                    <td data-label="{{ __('Source IP') }}" class="px-6 py-3 whitespace-nowrap text-sm font-mono text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-start max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:font-sans max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:shrink-0">
                                        <span class="max-md:text-right">
                                            <x-country-flag :code="$record->country" /> {{ $record->source_ip }}
                                            @if ($record->ptr_hostname)
                                                <div class="text-xs text-gray-400 dark:text-gray-500">{{ $record->ptr_hostname }}</div>
                                            @endif
                                            @if ($record->asn_org)
                                                <div class="text-xs text-gray-400 dark:text-gray-500">{{ $record->asn_org }}</div>
                                            @endif
                                        </span>
                                    </td>
                                    <td data-label="{{ __('Count') }}" class="px-6 py-3 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400 tabular-nums max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:text-left max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ number_format($record->count) }}</td>
                                    <td data-label="{{ __('Disposition') }}" class="px-6 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $record->disposition }}</td>
                                    <td data-label="{{ __('DKIM') }}" class="px-6 py-3 whitespace-nowrap text-sm max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $record->dkim_result === 'pass',
                                            'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $record->dkim_result === 'fail',
                                        ])>{{ $record->dkim_result }}</span>
                                    </td>
                                    <td data-label="{{ __('SPF') }}" class="px-6 py-3 whitespace-nowrap text-sm max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $record->spf_result === 'pass',
                                            'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $record->spf_result === 'fail',
                                        ])>{{ $record->spf_result }}</span>
                                    </td>
                                    <td data-label="{{ __('Header From') }}" class="px-6 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $record->header_from ?? '—' }}</td>
                                    <td data-label="{{ __('Envelope From') }}" class="px-6 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $record->envelope_from ?? '—' }}</td>
                                    <td data-label="{{ __('Envelope To') }}" class="px-6 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $record->envelope_to ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">
                                        {{ $spf_result || $dkim_result || $disposition ? __('No records match these filters.') : __('No records in this report.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
