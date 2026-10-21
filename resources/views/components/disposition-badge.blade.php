@props(['disposition'])

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
    'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $disposition === 'none',
    'bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200' => $disposition === 'quarantine',
    'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $disposition === 'reject',
    'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' => ! in_array($disposition, ['none', 'quarantine', 'reject'], true),
]) }}>{{ $disposition ?? '—' }}</span>
