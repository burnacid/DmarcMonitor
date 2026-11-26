<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination, WithSorting;

    #[Url]
    public ?int $domain_id = null;

    #[Url]
    public string $ip = '';

    #[Url]
    public string $envelope = '';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $spf_result = '';

    #[Url]
    public string $dkim_result = '';

    #[Url]
    public string $disposition = '';

    /** Records tab only: leaves out records that match an expected source. */
    #[Url(as: 'hide_expected')]
    public bool $hideExpected = false;

    /** "reports" lists one row per report, "records" one row per record across reports. */
    #[Url]
    public string $view = 'reports';

    public const array VIEWS = ['reports', 'records'];

    public function mount(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'reports';
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * Switches between the reports and records lists. The filters are kept;
     * the sort is cleared, since each list sorts on its own columns.
     */
    public function selectTab(string $tab): void
    {
        $this->view = in_array($tab, self::VIEWS, true) ? $tab : 'reports';
        $this->reset(['sortBy', 'sortDirection']);
        unset($this->currentSort);
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['domain_id', 'ip', 'envelope', 'from', 'to', 'search', 'spf_result', 'dkim_result', 'disposition', 'hideExpected']);
    }

    /**
     * @return array<string, 'asc'|'desc'>
     */
    protected function sortableColumns(): array
    {
        if ($this->view === 'records') {
            return [
                'date' => 'desc', 'domain' => 'asc', 'org_name' => 'asc', 'source_ip' => 'asc', 'count' => 'desc',
                'disposition' => 'asc', 'dkim_result' => 'asc', 'spf_result' => 'asc', 'header_from' => 'asc',
            ];
        }

        return ['domain' => 'asc', 'org_name' => 'asc', 'date' => 'desc', 'records' => 'desc', 'messages' => 'desc'];
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function defaultSort(): array
    {
        return ['date', 'desc'];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'domain_id' => $this->domain_id,
            'ip' => $this->ip,
            'envelope' => $this->envelope,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'spf_result' => $this->spf_result,
            'dkim_result' => $this->dkim_result,
            'disposition' => $this->disposition,
            'hide_expected' => $this->view === 'records' && $this->hideExpected,
        ];
    }

    private function applySort(Builder $query): void
    {
        ['column' => $column, 'direction' => $direction] = $this->currentSort;

        match ($column) {
            'domain' => $query->orderBy(DB::table('domains')->select('fqdn')->whereColumn('domains.id', 'aggregate_reports.domain_id'), $direction),
            'org_name' => $query->orderBy('org_name', $direction),
            'records' => $query->orderBy('records_count', $direction),
            'messages' => $query->orderBy('message_count', $direction),
            default => $query->orderBy('date_range_begin', $direction),
        };
    }

    private function applyRecordSort(Builder $query): void
    {
        ['column' => $column, 'direction' => $direction] = $this->currentSort;

        match ($column) {
            'domain' => $query->orderBy(DB::table('domains')->select('fqdn')->whereColumn('domains.id', 'aggregate_reports.domain_id'), $direction),
            'org_name' => $query->orderBy('aggregate_reports.org_name', $direction),
            'date' => $query->orderBy('aggregate_reports.date_range_begin', $direction),
            default => $query->orderBy("aggregate_report_records.{$column}", $direction),
        };
    }

    public function with(): array
    {
        $records = $this->view === 'records'
            ? AggregateReportRecord::query()
                ->filter($this->filters(), auth()->user())
                ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
                ->select('aggregate_report_records.*')
                ->with(['aggregateReport.domain', 'expectedSource'])
                ->tap(fn (Builder $query) => $this->applyRecordSort($query))
                ->orderByDesc('aggregate_report_records.id')
                ->paginate(50)
            : null;

        $reports = $this->view === 'reports'
            ? AggregateReport::query()
                ->visibleTo(auth()->user())
                ->filter($this->filters())
                ->with('domain')
                ->withCount('records')
                ->withSum('records as message_count', 'count')
                ->tap(fn (Builder $query) => $this->applySort($query))
                ->orderByDesc('id')
                ->paginate(15)
            : null;

        return [
            'reports' => $reports,
            'records' => $records,
            'domains' => Domain::visibleTo(auth()->user())->orderBy('fqdn')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Reports') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-tabs :tabs="['reports' => __('Reports'), 'records' => __('Records')]" :active="$view" />

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <x-input-label for="domain_id" :value="__('Domain')" />
                    <x-searchable-select wire:model.live="domain_id" id="domain_id" :options="$domains->pluck('fqdn', 'id')" :placeholder="__('All domains')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="from" :value="__('From')" />
                    <x-text-input wire:model.live="from" id="from" type="date" class="mt-1 block text-sm" />
                </div>

                <div>
                    <x-input-label for="to" :value="__('To')" />
                    <x-text-input wire:model.live="to" id="to" type="date" class="mt-1 block text-sm" />
                </div>

                <div class="flex-1 min-w-[12rem]">
                    <x-input-label for="search" :value="$view === 'records' ? __('Search (IP, hostname, ASN, domain or org)') : __('Search (org or report ID)')" />
                    <x-text-input wire:model.live.debounce.400ms="search" id="search" type="text" class="mt-1 block w-full text-sm" />
                </div>

                @if ($ip)
                    <div>
                        <x-input-label :value="__('Source IP(s)')" />
                        <div class="mt-1 font-mono text-sm text-gray-700 dark:text-gray-300 py-2">{{ $ip }}</div>
                    </div>
                @endif

                <div>
                    <x-input-label for="envelope" :value="__('Envelope (from or to)')" />
                    <x-text-input wire:model.live.debounce.400ms="envelope" id="envelope" type="text" class="mt-1 block text-sm" />
                </div>

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

                @if ($view === 'records')
                    <label class="inline-flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300" title="{{ __('Leave out records that match a source marked as expected under Settings > Expected Sources.') }}">
                        <input wire:model.live="hideExpected" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                        {{ __('Hide expected') }}
                    </label>
                @endif

                @if ($domain_id || $ip || $envelope || $from || $to || $search || $spf_result || $dkim_result || $disposition || ($view === 'records' && $hideExpected))
                    <x-secondary-button wire:click="clearFilters">{{ __('Clear filters') }}</x-secondary-button>
                @endif

                <a href="{{ route('reports.export', array_merge($this->filters(), $view === 'records' ? ['view' => 'records'] : [])) }}" class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                    {{ __('Export CSV') }}
                </a>
            </div>

            @if ($view === 'reports')
            <div class="bg-white dark:bg-gray-800 overflow-x-auto shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <x-sortable-th column="domain" :sort="$this->currentSort">{{ __('Domain') }}</x-sortable-th>
                            <x-sortable-th column="org_name" :sort="$this->currentSort">{{ __('Reporting org') }}</x-sortable-th>
                            <x-sortable-th column="date" :sort="$this->currentSort">{{ __('Date range') }}</x-sortable-th>
                            <x-sortable-th column="records" align="right" :sort="$this->currentSort">{{ __('Records') }}</x-sortable-th>
                            <x-sortable-th column="messages" align="right" :sort="$this->currentSort">{{ __('Messages') }}</x-sortable-th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($reports as $report)
                            <tr wire:key="report-{{ $report->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Domain') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $report->domain->fqdn }}</td>
                                <td data-label="{{ __('Reporting org') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $report->org_name }}</td>
                                <td data-label="{{ __('Date range') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $report->date_range_begin->format('Y-m-d') }} &rarr; {{ $report->date_range_end->format('Y-m-d') }}</td>
                                <td data-label="{{ __('Records') }}" class="px-6 py-4 whitespace-nowrap text-right text-gray-500 dark:text-gray-400 tabular-nums max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:text-left max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ number_format($report->records_count) }}</td>
                                <td data-label="{{ __('Messages') }}" class="px-6 py-4 whitespace-nowrap text-right text-gray-500 dark:text-gray-400 tabular-nums max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:text-left max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ number_format($report->message_count) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm max-md:px-0 max-md:py-0 max-md:pt-1">
                                    <a href="{{ route('reports.show', $report) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No reports match these filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>
                {{ $reports->links() }}
            </div>
            @else
                @php
                    $cell = 'px-6 py-3 text-sm max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:font-sans max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:shrink-0';
                    $resultPill = fn (?string $result) => match ($result) {
                        'pass' => 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
                        'fail' => 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200',
                        default => '',
                    };
                @endphp
                <div class="bg-white dark:bg-gray-800 overflow-x-auto shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                        <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                            <tr>
                                <x-sortable-th column="date" :sort="$this->currentSort">{{ __('Date range') }}</x-sortable-th>
                                <x-sortable-th column="domain" :sort="$this->currentSort">{{ __('Domain') }}</x-sortable-th>
                                <x-sortable-th column="org_name" :sort="$this->currentSort">{{ __('Reporting org') }}</x-sortable-th>
                                <x-sortable-th column="source_ip" :sort="$this->currentSort">{{ __('Source IP') }}</x-sortable-th>
                                <x-sortable-th column="count" align="right" :sort="$this->currentSort">{{ __('Count') }}</x-sortable-th>
                                <x-sortable-th column="disposition" :sort="$this->currentSort">{{ __('Disposition') }}</x-sortable-th>
                                <x-sortable-th column="dkim_result" :sort="$this->currentSort">{{ __('DKIM') }}</x-sortable-th>
                                <x-sortable-th column="spf_result" :sort="$this->currentSort">{{ __('SPF') }}</x-sortable-th>
                                <x-sortable-th column="header_from" :sort="$this->currentSort">{{ __('Header From') }}</x-sortable-th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Envelope From') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Envelope To') }}</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                            @forelse ($records as $record)
                                @php($report = $record->aggregateReport)
                                <tr wire:key="record-{{ $record->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                    <td data-label="{{ __('Date range') }}" class="{{ $cell }} whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $report->date_range_begin->format('Y-m-d') }} &rarr; {{ $report->date_range_end->format('Y-m-d') }}</td>
                                    <td data-label="{{ __('Domain') }}" class="{{ $cell }} whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">{{ $report->domain?->fqdn }}</td>
                                    <td data-label="{{ __('Reporting org') }}" class="{{ $cell }} whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $report->org_name }}</td>
                                    <td data-label="{{ __('Source IP') }}" class="{{ $cell }} whitespace-nowrap font-mono text-gray-900 dark:text-gray-100 max-md:items-start">
                                        <span class="max-md:text-right">
                                            <x-country-flag :code="$record->country" /> {{ $record->source_ip }}
                                            @if ($record->ptr_hostname)
                                                <div class="font-sans text-xs text-gray-400 dark:text-gray-500">{{ $record->ptr_hostname }}</div>
                                            @endif
                                            @if ($record->asn_org)
                                                <div class="font-sans text-xs text-gray-400 dark:text-gray-500">{{ $record->asn_org }}</div>
                                            @endif
                                        </span>
                                    </td>
                                    <td data-label="{{ __('Count') }}" class="{{ $cell }} whitespace-nowrap text-right text-gray-500 dark:text-gray-400 tabular-nums max-md:text-left">{{ number_format($record->count) }}</td>
                                    <td data-label="{{ __('Disposition') }}" class="{{ $cell }} whitespace-nowrap">
                                        <x-disposition-badge :disposition="$record->disposition" />
                                    </td>
                                    <td data-label="{{ __('DKIM') }}" class="{{ $cell }} whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $resultPill($record->dkim_result) }}">{{ $record->dkim_result }}</span>
                                    </td>
                                    <td data-label="{{ __('SPF') }}" class="{{ $cell }} whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $resultPill($record->spf_result) }}">{{ $record->spf_result }}</span>
                                    </td>
                                    <td data-label="{{ __('Header From') }}" class="{{ $cell }} whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $record->header_from ?? '—' }}</td>
                                    <td data-label="{{ __('Envelope From') }}" class="{{ $cell }} whitespace-nowrap text-gray-500 dark:text-gray-400">
                                        <span class="max-md:text-right">
                                            {{ $record->envelope_from ?? '—' }}
                                            <x-source-badges
                                                :null-sender="$record->is_null_sender"
                                                :helo-domains="$record->spf_scope === 'helo' ? [$record->spf_domain] : []"
                                                :expected-labels="$record->expectedSource ? [$record->expectedSource->label] : []"
                                                class="ml-1"
                                            />
                                        </span>
                                    </td>
                                    <td data-label="{{ __('Envelope To') }}" class="{{ $cell }} whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $record->envelope_to ?? '—' }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-sm max-md:block max-md:px-0 max-md:py-0 max-md:pt-1">
                                        <a href="{{ route('reports.show', ['report' => $report, 'spf_result' => $record->spf_result, 'dkim_result' => $record->dkim_result, 'disposition' => $record->disposition]) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View report') }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No records match these filters.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div>
                    {{ $records->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
