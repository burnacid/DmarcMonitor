@props(['entries'])

@if ($entries->isNotEmpty())
    <div x-data="{ open: false }" class="mt-3">
        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" class="flex items-center gap-1 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
            <svg class="h-3 w-3 transition-transform" x-bind:class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
            </svg>
            {{ __('Previous records (:count)', ['count' => $entries->count()]) }}
        </button>
        <ul x-show="open" x-cloak class="mt-1 space-y-2">
            @foreach ($entries as $entry)
                <li class="text-xs">
                    <span class="text-gray-400 dark:text-gray-500" title="{{ $entry->created_at->toDayDateTimeString() }}">{{ __('Until :date', ['date' => $entry->created_at->toFormattedDateString()]) }}</span>
                    <p class="font-mono break-all text-gray-600 dark:text-gray-300">{{ $entry->record }}</p>
                </li>
            @endforeach
        </ul>
    </div>
@endif
