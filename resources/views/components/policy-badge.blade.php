@props(['policy'])

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
    'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $policy === 'reject',
    'bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200' => $policy === 'quarantine',
    'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => $policy === 'none',
    'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' => ! in_array($policy, ['reject', 'quarantine', 'none'], true),
]) }}>{{ $slot->isEmpty() ? ($policy ?? __('None published')) : $slot }}</span>
