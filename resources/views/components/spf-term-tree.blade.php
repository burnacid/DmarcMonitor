@props(['nodes', 'removable' => false])

<ul {{ $attributes->merge(['class' => 'space-y-1']) }}>
    @foreach ($nodes as $index => $node)
        <li x-data="{ open: false }" class="text-xs">
            <div class="flex items-start gap-2">
                @if ($node['children'] !== [])
                    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" class="mt-0.5 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" title="{{ __('Show what this includes') }}">
                        <svg class="h-3 w-3 transition-transform" x-bind:class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
                        </svg>
                    </button>
                @else
                    <span class="w-3 shrink-0" aria-hidden="true"></span>
                @endif

                <span class="font-mono break-all {{ $node['ignored'] ? 'text-gray-400 dark:text-gray-500 line-through' : 'text-gray-800 dark:text-gray-200' }}">{{ $node['term'] }}</span>

                @if ($node['lookups'] > 0)
                    <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $node['lookups'] > 1 ? 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">{{ trans_choice(':count lookup|:count lookups', $node['lookups']) }}</span>
                @endif

                @if ($node['ignored'])
                    <span class="shrink-0 text-gray-400 dark:text-gray-500">{{ __('ignored: the record has "all"') }}</span>
                @endif

                @if ($removable)
                    <button type="button" wire:click="removeSpfTerm({{ $index }})" class="ms-auto shrink-0 text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Remove') }}</button>
                @endif
            </div>

            @if ($node['error'])
                <p class="ms-5 text-red-700 dark:text-red-400">{{ $node['error'] }}</p>
            @endif

            @if ($node['children'] !== [])
                <x-spf-term-tree :nodes="$node['children']" x-show="open" x-cloak class="ms-5 mt-1 border-s border-gray-200 dark:border-gray-700 ps-2" />
            @endif
        </li>
    @endforeach
</ul>
