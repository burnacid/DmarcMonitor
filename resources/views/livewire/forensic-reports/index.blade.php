<?php

use App\Models\Domain;
use App\Models\ForensicReport;
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
        $this->reset(['domain_id', 'ip', 'from', 'to', 'search']);
    }

    public function with(): array
    {
        $reports = ForensicReport::query()
            ->with('domain')
            ->when($this->domain_id, fn (Builder $query) => $query->where('domain_id', $this->domain_id))
            ->when($this->ip, fn (Builder $query) => $query->where('source_ip', $this->ip))
            ->when($this->from, fn (Builder $query) => $query->whereDate('arrival_date', '>=', $this->from))
            ->when($this->to, fn (Builder $query) => $query->whereDate('arrival_date', '<=', $this->to))
            ->when($this->search, fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('subject', 'like', "%{$this->search}%")
                    ->orWhere('header_from', 'like', "%{$this->search}%")
            ))
            ->orderByDesc('arrival_date')
            ->paginate(15);

        return [
            'reports' => $reports,
            'domains' => Domain::orderBy('fqdn')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Forensic Reports') }}</h2>
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
                    <x-input-label for="ip" :value="__('Source IP')" />
                    <x-text-input wire:model.live.debounce.400ms="ip" id="ip" type="text" class="mt-1 block text-sm" />
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
                    <x-input-label for="search" :value="__('Search (subject or header-from)')" />
                    <x-text-input wire:model.live.debounce.400ms="search" id="search" type="text" class="mt-1 block w-full text-sm" />
                </div>

                @if ($domain_id || $ip || $from || $to || $search)
                    <x-secondary-button wire:click="clearFilters">{{ __('Clear filters') }}</x-secondary-button>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domain') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Source IP') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Delivery Result') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Header/Envelope From') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Arrival Date') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($reports as $report)
                            <tr wire:key="forensic-report-{{ $report->id }}">
                                <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">{{ $report->domain->fqdn }}</td>
                                <td class="px-6 py-4 whitespace-nowrap font-mono text-gray-500 dark:text-gray-400">{{ $report->source_ip ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    @if ($report->delivery_result)
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => in_array($report->delivery_result, ['reject', 'spam']),
                                            'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => in_array($report->delivery_result, ['policy', 'other']),
                                            'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $report->delivery_result === 'delivered',
                                        ])>{{ $report->delivery_result }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $report->header_from ?? $report->envelope_from ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $report->arrival_date?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <a href="{{ route('forensic-reports.show', $report) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('View') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No forensic reports match these filters.') }}</td>
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
