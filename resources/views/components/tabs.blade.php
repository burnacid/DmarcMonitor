@props([
    /** @var array<string, string> Tab key => label. */
    'tabs',
    'active',
    /** @var array<string, int> Tab key => count shown as a red badge when above zero. */
    'badges' => [],
])

<nav class="flex flex-wrap gap-x-6 border-b border-gray-200 dark:border-gray-700" role="tablist">
    @foreach ($tabs as $key => $label)
        <button
            type="button"
            role="tab"
            wire:click="selectTab('{{ $key }}')"
            aria-selected="{{ $active === $key ? 'true' : 'false' }}"
            @class([
                'inline-flex items-center -mb-px border-b-2 px-1 py-2 text-sm font-medium transition',
                'border-indigo-500 text-gray-900 dark:text-gray-100' => $active === $key,
                'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600' => $active !== $key,
            ])
        >
            {{ $label }}
            @if (($badges[$key] ?? 0) > 0)
                <span class="ml-1.5 inline-flex items-center justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-medium leading-none text-white">{{ $badges[$key] }}</span>
            @endif
        </button>
    @endforeach
</nav>
