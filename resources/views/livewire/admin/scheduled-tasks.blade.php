<?php

use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTaskRun;
use App\Support\ScheduledTaskTracker;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?string $message = null;

    public function mount(): void
    {
        ScheduledTaskTracker::ensureBootstrapped();
    }

    public function runNow(string $taskKey): void
    {
        RunScheduledTaskJob::dispatch($taskKey, auth()->id())->afterResponse();

        $this->message = __('Started in the background — this list refreshes automatically.');
    }

    public function with(): array
    {
        ScheduledTaskTracker::ensureBootstrapped();

        $runs = ScheduledTaskRun::with('triggeredByUser')
            ->whereIn('task_key', ScheduledTaskTracker::keys())
            ->get()
            ->keyBy('task_key');

        $tasks = collect(ScheduledTaskTracker::keys())->map(function (string $key) use ($runs) {
            $event = ScheduledTaskTracker::get($key);
            $run = $runs->get($key);

            return [
                'key' => $key,
                'label' => $event->description ?? $key,
                'expression' => $event->getExpression(),
                'run' => $run,
                'is_running' => $run?->started_at !== null && $run?->finished_at === null,
            ];
        });

        return [
            'tasks' => $tasks,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Scheduled Tasks') }}</h2>
    </x-slot>

    <div class="py-8" wire:poll.5s>
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            @if ($message)
                <div class="mb-4 rounded-md bg-white dark:bg-gray-800 shadow-sm p-4 text-sm text-green-700 dark:text-green-400">
                    {{ $message }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Task') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Schedule') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Last Run') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Triggered By') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @foreach ($tasks as $task)
                            <tr wire:key="task-{{ $task['key'] }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Task') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">{{ $task['label'] }}</td>
                                <td data-label="{{ __('Schedule') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 font-mono text-xs max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $task['expression'] }}</td>
                                <td data-label="{{ __('Last Run') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if ($task['is_running'])
                                        <span class="inline-flex items-center rounded-full bg-blue-100 dark:bg-blue-900 px-2 py-0.5 text-xs font-medium text-blue-800 dark:text-blue-200">{{ __('Running…') }}</span>
                                    @elseif (! $task['run'])
                                        <span class="text-gray-400 dark:text-gray-500">{{ __('Never run') }}</span>
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">{{ $task['run']->finished_at->diffForHumans() }}</span>
                                        @if ($task['run']->exit_code === 0)
                                            <span class="ml-2 inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Success') }}</span>
                                        @else
                                            <span class="ml-2 inline-flex items-center rounded-full bg-red-100 dark:bg-red-900 px-2 py-0.5 text-xs font-medium text-red-800 dark:text-red-200" title="{{ __('Exit code :code', ['code' => $task['run']->exit_code]) }}">{{ __('Failed') }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td data-label="{{ __('Triggered By') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if (! $task['run'])
                                        —
                                    @elseif ($task['run']->triggered_by === 'manual')
                                        {{ __('Manually by :user', ['user' => $task['run']->triggeredByUser?->name ?? __('unknown user')]) }}
                                    @else
                                        {{ __('Schedule') }}
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm max-md:px-0 max-md:py-0 max-md:pt-1">
                                    <button wire:click="runNow('{{ $task['key'] }}')" wire:loading.attr="disabled" @disabled($task['is_running']) class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-900 dark:hover:text-emerald-300 disabled:opacity-50 disabled:cursor-not-allowed">
                                        <span wire:loading.remove wire:target="runNow('{{ $task['key'] }}')">{{ $task['is_running'] ? __('Running…') : __('Run now') }}</span>
                                        <span wire:loading wire:target="runNow('{{ $task['key'] }}')">{{ __('Starting…') }}</span>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
