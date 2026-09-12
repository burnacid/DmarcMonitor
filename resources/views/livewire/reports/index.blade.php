<?php

use App\Models\AggregateReport;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

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

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['domain_id', 'ip', 'envelope', 'from', 'to', 'search']);
    }

    public function with(): array
    {
        $reports = AggregateReport::query()
            ->with('domain')
            ->withCount('records')
            ->withSum('records as message_count', 'count')
            ->when($this->domain_id, fn (Builder $query) => $query->where('domain_id', $this->domain_id))
            ->when($this->from, fn (Builder $query) => $query->whereDate('date_range_begin', '>=', $this->from))
            ->when($this->to, fn (Builder $query) => $query->whereDate('date_range_begin', '<=', $this->to))
            ->when($this->search, fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('org_name', 'like', "%{$this->search}%")
                    ->orWhere('report_id', 'like', "%{$this->search}%")
            ))
            ->when($this->ip, function (Builder $query) {
                $ips = collect(explode(',', $this->ip))->map(fn ($ip) => trim($ip))->filter()->all();

                $query->whereHas('records', fn (Builder $q) => $q->whereIn('source_ip', $ips));
            })
            ->when($this->envelope, fn (Builder $query) => $query->whereHas('records', fn (Builder $q) => $q
                ->where('envelope_from', 'like', "%{$this->envelope}%")
                ->orWhere('envelope_to', 'like', "%{$this->envelope}%")
            ))
            ->orderByDesc('date_range_begin')
            ->paginate(15);

        return [
            'reports' => $reports,
            'domains' => Domain::orderBy('fqdn')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Reports') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <x-input-label for="domain_id" :value="__('Domain')" />
                    <select wire:model.live="domain_id" id="domain_id" class="mt-1 border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">{{ __('All domains') }}</option>
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->fqdn }}</option>
                        @endforeach
                    </select>
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
                    <x-input-label for="search" :value="__('Search (org or report ID)')" />
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

                @if ($domain_id || $ip || $envelope || $from || $to || $search)
                    <x-secondary-button wire:click="clearFilters">{{ __('Clear filters') }}</x-secondary-button>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domain') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Reporting org') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Date range') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Records') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Messages') }}</th>
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
        </div>
    </div>
</div>
