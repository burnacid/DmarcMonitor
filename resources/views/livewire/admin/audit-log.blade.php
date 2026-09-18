<?php

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public const array CATEGORIES = [
        'admin' => ['domain', 'user', 'organisation', 'imap_account', 'microsoft365_mail_account', 'microsoft365_send_account', 'alert_rule', 'alert_event'],
        'auth' => ['auth'],
        'ingestion' => ['ingestion'],
        'scheduled_task' => ['scheduled_task'],
    ];

    public string $category = 'all';

    public string $userId = 'all';

    public string $from = '';

    public string $to = '';

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $prefixes = self::CATEGORIES[$this->category] ?? null;

        $entries = AuditLog::visibleTo(auth()->user())
            ->with(['user', 'organisation'])
            ->when($prefixes, fn ($query) => $query->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('action', 'like', "{$prefix}.%");
                }
            }))
            ->when($this->userId !== 'all', fn ($query) => $query->where('user_id', $this->userId))
            ->when($this->from !== '', fn ($query) => $query->where('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($query) => $query->where('created_at', '<=', $this->to.' 23:59:59'))
            ->orderByDesc('created_at')
            ->paginate(20);

        return [
            'entries' => $entries,
            'users' => User::orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Audit Log') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end gap-3 mb-4">
                <div>
                    <x-input-label for="category" :value="__('Category')" />
                    <select wire:model.live="category" id="category" class="mt-1 block border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="all">{{ __('All') }}</option>
                        <option value="admin">{{ __('Admin changes') }}</option>
                        <option value="auth">{{ __('Authentication') }}</option>
                        <option value="ingestion">{{ __('Ingestion') }}</option>
                        <option value="scheduled_task">{{ __('Scheduled tasks') }}</option>
                    </select>
                </div>

                <div>
                    <x-input-label for="userId" :value="__('User')" />
                    <select wire:model.live="userId" id="userId" class="mt-1 block border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="all">{{ __('All') }}</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
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
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('When') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Actor') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Description') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Organisation') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($entries as $entry)
                            <tr wire:key="audit-{{ $entry->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('When') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400" title="{{ $entry->created_at }}">{{ $entry->created_at->diffForHumans() }}</td>
                                <td data-label="{{ __('Actor') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">{{ $entry->user?->name ?? __('System') }}</td>
                                <td data-label="{{ __('Description') }}" class="px-6 py-4 text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-start max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:shrink-0">{{ $entry->description }}</td>
                                <td data-label="{{ __('Organisation') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $entry->organisation?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No audit log entries yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $entries->links() }}
            </div>
        </div>
    </div>
</div>
