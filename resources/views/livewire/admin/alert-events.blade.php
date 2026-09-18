<?php

use App\Models\AlertEvent;
use App\Support\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $status = 'open';

    public function resolve(int $id): void
    {
        $event = AlertEvent::visibleTo(auth()->user())->findOrFail($id);
        $event->update(['resolved_at' => now()]);

        AuditLogger::record(
            action: 'alert_event.resolved',
            description: 'Resolved alert event #'.$event->id,
            subject: $event,
            organisationId: $event->domain?->organisation_id,
        );
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $events = AlertEvent::visibleTo(auth()->user())->with(['alertRule', 'domain'])
            ->when($this->status === 'open', fn ($query) => $query->whereNull('resolved_at'))
            ->when($this->status === 'resolved', fn ($query) => $query->whereNotNull('resolved_at'))
            ->orderByDesc('fired_at')
            ->paginate(15);

        return [
            'events' => $events,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Alert Events') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-end mb-4">
                <select wire:model.live="status" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                    <option value="open">{{ __('Open') }}</option>
                    <option value="resolved">{{ __('Resolved') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </select>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Fired at') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domain') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Type') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Details') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Notified') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($events as $event)
                            <tr wire:key="event-{{ $event->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Fired at') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $event->fired_at->diffForHumans() }}</td>
                                <td data-label="{{ __('Domain') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">{{ $event->domain->fqdn }}</td>
                                <td data-label="{{ __('Type') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ str_replace('_', ' ', $event->alertRule->type) }}</td>
                                <td data-label="{{ __('Details') }}" class="px-6 py-4 text-xs text-gray-500 dark:text-gray-400 font-mono max-md:flex max-md:justify-between max-md:items-start max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:font-sans max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:shrink-0">
                                    <span class="max-md:text-right">
                                        @foreach ($event->details ?? [] as $key => $value)
                                            <div>{{ $key }}: {{ is_array($value) ? json_encode($value) : $value }}</div>
                                        @endforeach
                                    </span>
                                </td>
                                <td data-label="{{ __('Notified') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ implode(', ', $event->notified_channels ?? []) ?: '—' }}</td>
                                <td data-label="{{ __('Status') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if ($event->resolved_at)
                                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Resolved') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-red-100 dark:bg-red-900 px-2 py-0.5 text-xs font-medium text-red-800 dark:text-red-200">{{ __('Open') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm max-md:px-0 max-md:py-0 max-md:pt-1">
                                    @unless ($event->resolved_at)
                                        <button wire:click="resolve({{ $event->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Resolve') }}</button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No alert events.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $events->links() }}
            </div>
        </div>
    </div>
</div>
