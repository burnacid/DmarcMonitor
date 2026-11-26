<?php

use App\Livewire\Concerns\WithSorting;
use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\ExpectedSource;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    #[Url(as: 'organisation')]
    public ?int $organisation_id = null;

    /** Only records matched by this expected source, e.g. from its "Messages matched" link. */
    #[Url(as: 'expected_source')]
    public ?int $expected_source_id = null;

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

    /** @var list<string> The Records columns shown, saved on the user. */
    public array $visibleColumns = [];

    public function mount(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'reports';
        }

        $this->visibleColumns = auth()->user()->reportRecordColumns();

        if ($this->organisation_id !== null && ! auth()->user()->canAccessOrganisation($this->organisation_id)) {
            $this->organisation_id = null;
        }
    }

    /**
     * Drops a domain selection that doesn't belong to the newly chosen
     * organisation, as the dashboard does.
     */
    public function updatedOrganisationId(): void
    {
        if ($this->organisation_id !== null && ! auth()->user()->canAccessOrganisation($this->organisation_id)) {
            $this->organisation_id = null;
        }

        if ($this->domain_id !== null && $this->organisation_id !== null
            && Domain::find($this->domain_id)?->organisation_id !== $this->organisation_id) {
            $this->domain_id = null;
        }
    }

    /**
     * Shows or hides one Records column and remembers the choice on the
     * user's account. The last visible column can't be hidden.
     */
    public function toggleColumn(string $column): void
    {
        if (! in_array($column, User::REPORT_RECORD_COLUMNS, true)) {
            return;
        }

        $columns = in_array($column, $this->visibleColumns, true)
            ? array_diff($this->visibleColumns, [$column])
            : [...$this->visibleColumns, $column];

        if ($columns === []) {
            return;
        }

        $user = auth()->user();
        $user->update(['report_record_columns' => array_values(array_intersect(User::REPORT_RECORD_COLUMNS, $columns))]);
        $this->visibleColumns = $user->reportRecordColumns();
    }

    public function resetColumns(): void
    {
        auth()->user()->update(['report_record_columns' => null]);
        $this->visibleColumns = User::DEFAULT_REPORT_RECORD_COLUMNS;
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

    /**
     * The filters in use apart from the search box, for the chips above the
     * list: property => label shown on the chip.
     *
     * @param  \Illuminate\Support\Collection<int, Domain>  $domains
     * @return array<string, string>
     */
    private function activeFilters(Collection $domains): array
    {
        $resultLabels = ['pass' => __('Pass'), 'fail' => __('Fail'), 'none' => __('None'), 'quarantine' => __('Quarantine'), 'reject' => __('Reject')];

        return array_filter([
            'organisation_id' => $this->organisation_id ? __('Organisation: :value', ['value' => Organisation::find($this->organisation_id)?->name ?? $this->organisation_id]) : null,
            'domain_id' => $this->domain_id ? __('Domain: :value', ['value' => $domains->firstWhere('id', $this->domain_id)?->fqdn ?? $this->domain_id]) : null,
            'from' => $this->from ? __('From :date', ['date' => $this->from]) : null,
            'to' => $this->to ? __('To :date', ['date' => $this->to]) : null,
            'ip' => $this->ip ? __('Source IP: :value', ['value' => $this->ip]) : null,
            'envelope' => $this->envelope ? __('Envelope: :value', ['value' => $this->envelope]) : null,
            'spf_result' => $this->spf_result ? __('SPF: :value', ['value' => $resultLabels[$this->spf_result] ?? $this->spf_result]) : null,
            'dkim_result' => $this->dkim_result ? __('DKIM: :value', ['value' => $resultLabels[$this->dkim_result] ?? $this->dkim_result]) : null,
            'disposition' => $this->disposition ? __('Disposition: :value', ['value' => $resultLabels[$this->disposition] ?? $this->disposition]) : null,
            'expected_source_id' => $this->expected_source_id ? __('Expected source: :value', ['value' => ExpectedSource::query()->visibleTo(auth()->user())->find($this->expected_source_id)?->label ?? $this->expected_source_id]) : null,
            'hideExpected' => $this->view === 'records' && $this->hideExpected ? __('Expected hidden') : null,
        ]);
    }

    /**
     * Removes one filter, from the × on its chip.
     */
    public function removeFilter(string $filter): void
    {
        if (in_array($filter, ['organisation_id', 'domain_id', 'from', 'to', 'ip', 'envelope', 'spf_result', 'dkim_result', 'disposition', 'expected_source_id', 'hideExpected'], true)) {
            $this->reset($filter);
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['organisation_id', 'domain_id', 'ip', 'envelope', 'from', 'to', 'search', 'spf_result', 'dkim_result', 'disposition', 'expected_source_id', 'hideExpected']);
    }

    /**
     * @return array<string, 'asc'|'desc'>
     */
    protected function sortableColumns(): array
    {
        if ($this->view === 'records') {
            return ['date' => 'desc', 'domain' => 'asc', 'org_name' => 'asc', 'source_ip' => 'asc', 'count' => 'desc', 'header_from' => 'asc'];
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
            'organisation_id' => $this->organisation_id,
            'domain_id' => $this->domain_id,
            'ip' => $this->ip,
            'envelope' => $this->envelope,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
            'spf_result' => $this->spf_result,
            'dkim_result' => $this->dkim_result,
            'disposition' => $this->disposition,
            'expected_source_id' => $this->expected_source_id,
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

        $domains = Domain::visibleTo(auth()->user())
            ->when($this->organisation_id, fn (Builder $query, int $organisationId) => $query->where('organisation_id', $organisationId))
            ->orderBy('fqdn')
            ->get();

        return [
            'organisations' => Organisation::visibleTo(auth()->user())->orderBy('name')->get(),
            'reports' => $reports,
            'records' => $records,
            'domains' => $domains,
            'activeFilters' => $this->activeFilters($domains),
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

            @php
                $selectClasses = 'mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm';
                $toolbarButton = 'inline-flex items-center gap-1.5 px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700';
                $columnLabels = [
                    'report' => __('Report'),
                    'source' => __('Source'),
                    'count' => __('Count'),
                    'results' => __('Results'),
                    'addresses' => __('Addresses'),
                    'authentication' => __('Authentication'),
                ];
            @endphp
            <div x-data="{ filtersOpen: false }" class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <div class="flex flex-wrap items-center gap-3 p-4">
                    <div class="relative flex-1 min-w-[14rem]">
                        <label for="search" class="sr-only">{{ __('Search') }}</label>
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z" /></svg>
                        <x-text-input
                            wire:model.live.debounce.400ms="search"
                            id="search"
                            type="search"
                            class="block w-full pl-9 text-sm"
                            :placeholder="$view === 'records' ? __('Search IP, hostname, ASN, domain or org…') : __('Search org or report ID…')"
                        />
                    </div>

                    <button type="button" @click="filtersOpen = ! filtersOpen" :aria-expanded="filtersOpen" aria-controls="report-filters" class="{{ $toolbarButton }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4" /></svg>
                        {{ __('Filters') }}
                        @if (count($activeFilters) > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-medium leading-none text-white">{{ count($activeFilters) }}</span>
                        @endif
                        <svg :class="{ 'rotate-180': filtersOpen }" class="h-4 w-4 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                    </button>

                    @if ($view === 'records')
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                            <button type="button" @click="open = ! open" :aria-expanded="open" class="{{ $toolbarButton }}">
                                {{ __('Columns') }}
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                            </button>
                            <div x-show="open" x-transition style="display: none;" class="absolute right-0 z-50 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 shadow-lg ring-1 ring-black ring-opacity-5 p-2 space-y-1">
                                @foreach (\App\Models\User::REPORT_RECORD_COLUMNS as $column)
                                    @php $isShown = in_array($column, $visibleColumns, true); @endphp
                                    <label wire:key="column-{{ $column }}" class="flex items-center gap-2 rounded px-2 py-1 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer">
                                        <input type="checkbox" wire:click="toggleColumn('{{ $column }}')" @checked($isShown) @disabled($isShown && count($visibleColumns) === 1) class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                        {{ $columnLabels[$column] }}
                                    </label>
                                @endforeach
                                <div class="border-t border-gray-200 dark:border-gray-600 pt-1">
                                    <button type="button" wire:click="resetColumns" class="w-full rounded px-2 py-1 text-left text-xs text-indigo-600 dark:text-indigo-300 hover:bg-gray-100 dark:hover:bg-gray-600">{{ __('Reset to default') }}</button>
                                </div>
                            </div>
                        </div>
                    @endif

                    <a href="{{ route('reports.export', array_merge($this->filters(), $view === 'records' ? ['view' => 'records'] : [])) }}" class="{{ $toolbarButton }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16" /></svg>
                        {{ __('Export CSV') }}
                    </a>
                </div>

                <div id="report-filters" x-show="filtersOpen" x-transition.opacity style="display: none;" class="border-t border-gray-200 dark:border-gray-700 p-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <x-input-label for="organisation_id" :value="__('Organisation')" />
                            <x-searchable-select wire:model.live="organisation_id" id="organisation_id" :options="$organisations->pluck('name', 'id')" :placeholder="__('All organisations')" class="mt-1 w-full" />
                        </div>

                        <div>
                            <x-input-label for="domain_id" :value="__('Domain')" />
                            <x-searchable-select wire:model.live="domain_id" id="domain_id" wire:key="domain-filter-{{ $organisation_id ?? 'all' }}" :options="$domains->pluck('fqdn', 'id')" :placeholder="__('All domains')" class="mt-1 w-full" />
                        </div>

                        <div>
                            <x-input-label for="from" :value="__('Period')" />
                            <div class="mt-1 flex items-center gap-2">
                                <x-text-input wire:model.live="from" id="from" type="date" class="block w-full text-sm" :aria-label="__('From')" />
                                <span class="text-gray-400">&ndash;</span>
                                <x-text-input wire:model.live="to" id="to" type="date" class="block w-full text-sm" :aria-label="__('To')" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="envelope" :value="__('Envelope (from or to)')" />
                            <x-text-input wire:model.live.debounce.400ms="envelope" id="envelope" type="text" class="mt-1 block w-full text-sm" />
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <x-input-label for="spf_result" :value="__('SPF')" />
                                <select wire:model.live="spf_result" id="spf_result" class="{{ $selectClasses }}">
                                    <option value="">{{ __('Any') }}</option>
                                    <option value="pass">{{ __('Pass') }}</option>
                                    <option value="fail">{{ __('Fail') }}</option>
                                </select>
                            </div>
                            <div>
                                <x-input-label for="dkim_result" :value="__('DKIM')" />
                                <select wire:model.live="dkim_result" id="dkim_result" class="{{ $selectClasses }}">
                                    <option value="">{{ __('Any') }}</option>
                                    <option value="pass">{{ __('Pass') }}</option>
                                    <option value="fail">{{ __('Fail') }}</option>
                                </select>
                            </div>
                            <div>
                                <x-input-label for="disposition" :value="__('Disposition')" />
                                <select wire:model.live="disposition" id="disposition" class="{{ $selectClasses }}">
                                    <option value="">{{ __('Any') }}</option>
                                    <option value="none">{{ __('None') }}</option>
                                    <option value="quarantine">{{ __('Quarantine') }}</option>
                                    <option value="reject">{{ __('Reject') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    @if ($view === 'records')
                        <label class="mt-4 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300" title="{{ __('Leave out records that match a source marked as expected under Settings > Expected Sources.') }}">
                            <input wire:model.live="hideExpected" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            {{ __('Hide expected sources') }}
                        </label>
                    @endif
                </div>

                @if ($activeFilters !== [] || $search)
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 dark:border-gray-700 px-4 py-3">
                        @foreach ($activeFilters as $filter => $label)
                            <span wire:key="chip-{{ $filter }}" class="inline-flex items-center gap-1 rounded-full bg-indigo-50 dark:bg-indigo-900/50 py-0.5 pl-2.5 pr-1 text-xs font-medium text-indigo-700 dark:text-indigo-200">
                                {{ $label }}
                                <button type="button" wire:click="removeFilter('{{ $filter }}')" class="rounded-full p-0.5 hover:bg-indigo-100 dark:hover:bg-indigo-800" aria-label="{{ __('Remove filter :filter', ['filter' => $label]) }}">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </span>
                        @endforeach
                        <button type="button" wire:click="clearFilters" class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 underline">{{ __('Clear filters') }}</button>
                    </div>
                @endif
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
                    $authLine = fn (?string $domain, ?string $detail, ?string $result) => ($domain ?? '—')
                        .($detail ? " ({$detail})" : '')
                        .($result ? " · {$result}" : '');
                @endphp
                <div class="bg-white dark:bg-gray-800 overflow-x-auto shadow-sm sm:rounded-lg">
                    @php $shows = fn (string $column) => in_array($column, $visibleColumns, true); @endphp
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                        <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                            <tr>
                                @if ($shows('report'))
                                    <x-sortable-th column="date" :sort="$this->currentSort">{{ __('Report') }}</x-sortable-th>
                                @endif
                                @if ($shows('source'))
                                    <x-sortable-th column="source_ip" :sort="$this->currentSort">{{ __('Source') }}</x-sortable-th>
                                @endif
                                @if ($shows('count'))
                                    <x-sortable-th column="count" align="right" :sort="$this->currentSort">{{ __('Count') }}</x-sortable-th>
                                @endif
                                @if ($shows('results'))
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Results') }}</th>
                                @endif
                                @if ($shows('addresses'))
                                    <x-sortable-th column="header_from" :sort="$this->currentSort">{{ __('Addresses') }}</x-sortable-th>
                                @endif
                                @if ($shows('authentication'))
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Authentication') }}</th>
                                @endif
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                            @forelse ($records as $record)
                                @php $report = $record->aggregateReport; @endphp
                                <tr wire:key="record-{{ $record->id }}" class="align-top max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                    @if ($shows('report'))
                                        <td data-label="{{ __('Report') }}" class="{{ $cell }} max-md:items-start">
                                            <span class="max-md:text-right">
                                                <span class="block whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">{{ $report->domain?->fqdn }}</span>
                                                <span class="block whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $report->date_range_begin->format('Y-m-d') }} &rarr; {{ $report->date_range_end->format('Y-m-d') }}</span>
                                                <span class="block text-xs text-gray-400 dark:text-gray-500">{{ $report->org_name }}</span>
                                            </span>
                                        </td>
                                    @endif
                                    @if ($shows('source'))
                                        <td data-label="{{ __('Source') }}" class="{{ $cell }} max-md:items-start">
                                            <span class="max-md:text-right">
                                                <span class="block whitespace-nowrap font-mono text-gray-900 dark:text-gray-100"><x-country-flag :code="$record->country" /> {{ $record->source_ip }}</span>
                                                @if ($record->ptr_hostname)
                                                    <span class="block text-xs text-gray-400 dark:text-gray-500 break-all">{{ $record->ptr_hostname }}</span>
                                                @endif
                                                @if ($record->asn_org)
                                                    <span class="block text-xs text-gray-400 dark:text-gray-500">{{ $record->asn_org }}</span>
                                                @endif
                                            </span>
                                        </td>
                                    @endif
                                    @if ($shows('count'))
                                        <td data-label="{{ __('Count') }}" class="{{ $cell }} whitespace-nowrap text-right text-gray-700 dark:text-gray-300 tabular-nums max-md:text-left">{{ number_format($record->count) }}</td>
                                    @endif
                                    @if ($shows('results'))
                                        <td data-label="{{ __('Results') }}" class="{{ $cell }}">
                                            <span class="inline-flex flex-wrap items-center gap-1 max-md:justify-end">
                                                <x-disposition-badge :disposition="$record->disposition" />
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $resultPill($record->dkim_result) }}" title="{{ __('DKIM') }}">{{ __('DKIM') }} {{ $record->dkim_result }}</span>
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $resultPill($record->spf_result) }}" title="{{ __('SPF') }}">{{ __('SPF') }} {{ $record->spf_result }}</span>
                                            </span>
                                        </td>
                                    @endif
                                    @if ($shows('addresses'))
                                        <td data-label="{{ __('Addresses') }}" class="{{ $cell }} max-md:items-start">
                                            <span class="max-md:text-right">
                                                <span class="block text-gray-900 dark:text-gray-100 break-all" title="{{ __('Header From') }}">{{ $record->header_from ?? '—' }}</span>
                                                <span class="block text-xs text-gray-500 dark:text-gray-400 break-all">
                                                    {{ __('Envelope from') }}: {{ $record->envelope_from ?? '—' }}
                                                    <x-source-badges
                                                        :null-sender="$record->is_null_sender"
                                                        :helo-domains="$record->spf_scope === 'helo' ? [$record->spf_domain] : []"
                                                        :expected-labels="$record->expectedSource ? [$record->expectedSource->label] : []"
                                                        class="ml-1"
                                                    />
                                                </span>
                                                <span class="block text-xs text-gray-500 dark:text-gray-400 break-all">{{ __('Envelope to') }}: {{ $record->envelope_to ?? '—' }}</span>
                                            </span>
                                        </td>
                                    @endif
                                    @if ($shows('authentication'))
                                        <td data-label="{{ __('Authentication') }}" class="{{ $cell }} max-md:items-start">
                                            <span class="max-md:text-right text-xs text-gray-500 dark:text-gray-400">
                                                <span class="block break-all">{{ __('DKIM') }}: {{ $authLine($record->dkim_domain, $record->dkim_selector, $record->dkim_auth_result) }}</span>
                                                <span class="block break-all">{{ __('SPF') }}: {{ $authLine($record->spf_domain, $record->spf_scope, $record->spf_auth_result) }}</span>
                                            </span>
                                        </td>
                                    @endif
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-sm max-md:block max-md:px-0 max-md:py-0 max-md:pt-1">
                                        <a href="{{ route('reports.show', ['report' => $report, 'spf_result' => $record->spf_result, 'dkim_result' => $record->dkim_result, 'disposition' => $record->disposition]) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View report') }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($visibleColumns) + 1 }}" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No records match these filters.') }}</td>
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
