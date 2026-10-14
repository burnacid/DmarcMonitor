@props(['entries'])

@if ($entries->isNotEmpty())
    <h5 class="mt-3 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Previous records') }}</h5>
    <ul class="mt-1 space-y-2">
        @foreach ($entries as $entry)
            <li class="text-xs">
                <span class="text-gray-400 dark:text-gray-500" title="{{ $entry->created_at->toDayDateTimeString() }}">{{ __('Until :date', ['date' => $entry->created_at->toFormattedDateString()]) }}</span>
                <p class="font-mono break-all text-gray-600 dark:text-gray-300">{{ $entry->record }}</p>
            </li>
        @endforeach
    </ul>
@endif
