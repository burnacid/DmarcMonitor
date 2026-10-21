@props(['column', 'sort', 'align' => 'left'])

@php
    $isSorted = $sort['column'] === $column;
    $isAscending = $isSorted && $sort['direction'] === 'asc';
@endphp

<th
    {{ $attributes->class([
        'px-6 py-3 text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider',
        'text-right' => $align === 'right',
        'text-left' => $align !== 'right',
    ]) }}
    aria-sort="{{ $isSorted ? ($isAscending ? 'ascending' : 'descending') : 'none' }}"
>
    <button
        type="button"
        wire:click="sort('{{ $column }}')"
        @class([
            'group inline-flex items-center gap-1 uppercase tracking-wider hover:text-gray-700 dark:hover:text-gray-100',
            'flex-row-reverse' => $align === 'right',
            'text-gray-700 dark:text-gray-100' => $isSorted,
        ])
    >
        <span>{{ $slot }}</span>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" @class([
            'h-3.5 w-3.5 shrink-0 transition-transform',
            'rotate-180' => $isAscending,
            'opacity-0 group-hover:opacity-50' => ! $isSorted,
        ])>
            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
        </svg>
    </button>
</th>
