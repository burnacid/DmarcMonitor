@props(['status'])

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
    'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $status === 'good',
    'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => $status === 'warning',
    'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $status === 'critical',
    'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400' => ! in_array($status, ['good', 'warning', 'critical'], true),
]) }}>{{ $slot }}</span>
