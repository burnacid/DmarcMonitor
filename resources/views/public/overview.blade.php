<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta http-equiv="refresh" content="300">

        <title>{{ config('app.name', 'DMARC Monitor') }}</title>

        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="icon" href="/favicon.ico" sizes="any">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <script nonce="{{ app('csp-nonce') }}">
            document.documentElement.classList.toggle('dark', localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches));
        </script>

        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased">
        @php
            $panel = 'bg-white dark:bg-gray-800 shadow-sm rounded-lg flex flex-col lg:min-h-0 lg:overflow-hidden';
            $panelTitle = 'px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between text-sm font-semibold text-gray-700 dark:text-gray-200';
            $panelFooter = 'px-4 py-2 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400';
        @endphp

        <div class="min-h-screen lg:h-screen flex flex-col bg-gray-100 dark:bg-gray-900 lg:overflow-hidden">
            <header class="bg-white dark:bg-gray-800 shadow shrink-0">
                <div class="px-4 sm:px-6 py-3 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        @if ($logo)
                            <img src="{{ $logo }}" alt="" class="h-9 w-auto">
                        @else
                            <x-application-logo class="h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                        @endif
                        <h1 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ config('app.name', 'DMARC Monitor') }}</h1>
                    </div>

                    <div class="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                        <div class="inline-flex rounded-md shadow-sm" role="group">
                            @foreach ([7 => '7d', 30 => '30d', 90 => '90d'] as $value => $label)
                                <a href="{{ route('public.overview', ['days' => $value]) }}" @class([
                                    'px-3 py-1.5 text-sm font-medium border first:rounded-l-md last:rounded-r-md -ml-px first:ml-0',
                                    'bg-indigo-600 text-white border-indigo-600' => $days === $value,
                                    'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' => $days !== $value,
                                ])>{{ $label }}</a>
                            @endforeach
                        </div>
                        <span>{{ __('Updated :time', ['time' => now()->format('H:i')]) }}</span>
                        <button
                            type="button"
                            id="theme-toggle"
                            title="{{ __('Toggle dark mode') }}"
                            aria-label="{{ __('Toggle dark mode') }}"
                            class="p-2 rounded-md text-gray-400 dark:text-gray-300 hover:text-gray-500 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none transition ease-in-out duration-150"
                        >
                            <svg class="h-5 w-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            <svg class="h-5 w-5 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                            </svg>
                        </button>
                    </div>
                </div>
            </header>

            <main class="flex-1 lg:min-h-0 flex flex-col gap-4 p-4 sm:px-6">
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 shrink-0">
                    @foreach ([
                        ['label' => __('Active domains'), 'value' => number_format($totals['domains']), 'alert' => false],
                        ['label' => __('Need attention'), 'value' => number_format($totals['domains_with_issues']), 'alert' => $totals['domains_with_issues'] > 0],
                        ['label' => __('DMARC pass rate (:days d)', ['days' => $days]), 'value' => $totals['dmarc_pass_pct'] !== null ? $totals['dmarc_pass_pct'].'%' : '—', 'alert' => false],
                        ['label' => __('Enforced (p=reject)'), 'value' => number_format($totals['policies']['reject'] ?? 0).' / '.number_format($totals['domains']), 'alert' => false],
                        ['label' => __('Open alerts'), 'value' => number_format($alertCount), 'alert' => $alertCount > 0],
                    ] as $tile)
                        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</div>
                            <div @class([
                                'mt-1 text-2xl font-semibold',
                                'text-red-600 dark:text-red-400' => $tile['alert'],
                                'text-gray-900 dark:text-gray-100' => ! $tile['alert'],
                            ])>{{ $tile['value'] }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:flex-1 lg:min-h-0">
                    {{-- Organisations: problem domains listed, all-good organisations collapsed --}}
                    <section class="{{ $panel }}">
                        <h2 class="{{ $panelTitle }}">
                            <span>{{ __('Organisations') }}</span>
                            <span class="text-xs font-normal text-gray-400 dark:text-gray-500">{{ trans_choice(':count needs attention|:count need attention', $problemGroups->count()) }}</span>
                        </h2>
                        <div class="flex-1 lg:min-h-0 lg:overflow-hidden divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($problemGroups as $group)
                                <div class="px-4 py-2">
                                    <div class="flex items-center justify-between gap-2 text-sm">
                                        <span class="font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $group['name'] }}</span>
                                        <span class="shrink-0 text-xs text-red-600 dark:text-red-400 font-medium">{{ trans_choice(':count of :total domain|:count of :total domains', $group['problem_domains']->count(), ['total' => $group['domains']->count()]) }}</span>
                                    </div>
                                    <ul class="mt-1 space-y-1">
                                        @foreach ($group['problem_domains'] as $row)
                                            <li class="flex items-center gap-2 text-xs pl-3">
                                                <span class="text-gray-700 dark:text-gray-300 truncate">{{ $row['domain']->fqdn }}</span>
                                                <x-pass-badge :status="$row['pass_status']" class="shrink-0 !text-[10px] !px-1.5">{{ $row['total'] > 0 ? $row['dmarc_pass_pct'].'%' : __('No data') }}</x-pass-badge>
                                                <span class="flex flex-wrap gap-1 ml-auto justify-end">
                                                    @foreach ($row['issues'] as $issue)
                                                        <span class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/40 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:text-red-300 whitespace-nowrap">{{ \App\Services\Analytics\DomainHealthService::issueLabel($issue) }}</span>
                                                    @endforeach
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach

                            @if ($healthyGroups->isNotEmpty())
                                <div class="px-4 py-3">
                                    <div class="text-xs font-medium uppercase tracking-wider text-green-700 dark:text-green-400 mb-2">{{ __('All good') }}</div>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($healthyGroups as $group)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-green-50 dark:bg-green-900/30 px-2 py-0.5 text-xs text-green-800 dark:text-green-200" title="{{ trans_choice(':count domain|:count domains', $group['domains']->count()) }}">
                                                {{ $group['name'] }}
                                                <span class="text-green-600/70 dark:text-green-300/60">{{ $group['domains']->count() }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if ($problemGroups->isEmpty() && $healthyGroups->isEmpty())
                                <p class="px-4 py-8 text-center text-sm text-gray-400 dark:text-gray-500">{{ __('No active domains yet.') }}</p>
                            @endif
                        </div>
                    </section>

                    {{-- Open alerts --}}
                    <section class="{{ $panel }}">
                        <h2 class="{{ $panelTitle }}">
                            <span>{{ __('Open alerts') }}</span>
                            <span @class(['text-xs font-medium', 'text-red-600 dark:text-red-400' => $alertCount > 0, 'text-gray-400 dark:text-gray-500' => $alertCount === 0])>{{ number_format($alertCount) }}</span>
                        </h2>
                        <ul class="flex-1 lg:min-h-0 lg:overflow-hidden divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($alerts as $alert)
                                <li class="px-4 py-2 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $alert->domain->fqdn }}</span>
                                        <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">{{ $alert->fired_at->diffForHumans() }}</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 text-xs">
                                        <span class="text-red-600 dark:text-red-400 capitalize">{{ str_replace('_', ' ', $alert->alertRule->type) }}</span>
                                        <span class="text-gray-500 dark:text-gray-400 truncate">{{ $alert->domain->organisation?->name }}</span>
                                    </div>
                                </li>
                            @empty
                                <li class="px-4 py-8 text-center text-sm text-green-600 dark:text-green-400">{{ __('No open alerts.') }}</li>
                            @endforelse
                        </ul>
                        @if ($alertCount > $alerts->count())
                            <div class="{{ $panelFooter }}">{{ __('+:count more', ['count' => $alertCount - $alerts->count()]) }}</div>
                        @endif
                    </section>

                    {{-- Top failing sources --}}
                    <section class="{{ $panel }}">
                        <h2 class="{{ $panelTitle }}">
                            <span>{{ __('Top failing sources') }}</span>
                            <span class="text-xs font-normal text-gray-400 dark:text-gray-500">{{ __(':days days', ['days' => $days]) }}</span>
                        </h2>
                        <ul class="flex-1 lg:min-h-0 lg:overflow-hidden divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($failingSources as $source)
                                <li class="px-4 py-2 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $source['label'] }}</span>
                                        <x-pass-badge :status="\App\Services\Analytics\DomainHealthService::passStatus($source['dmarc_pass_pct'])" class="shrink-0">{{ $source['dmarc_pass_pct'] }}%</x-pass-badge>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="truncate">{{ $source['domain'] }}</span>
                                        <span class="shrink-0 tabular-nums">{{ trans_choice(':count message|:count messages', $source['total'], ['count' => number_format($source['total'])]) }}</span>
                                    </div>
                                </li>
                            @empty
                                <li class="px-4 py-8 text-center text-sm text-green-600 dark:text-green-400">{{ __('No failing sources.') }}</li>
                            @endforelse
                        </ul>
                    </section>
                </div>
            </main>
        </div>

        <script nonce="{{ app('csp-nonce') }}">
            document.getElementById('theme-toggle').addEventListener('click', () => {
                const isDark = ! document.documentElement.classList.contains('dark');
                localStorage.theme = isDark ? 'dark' : 'light';
                document.documentElement.classList.toggle('dark', isDark);
            });
        </script>
    </body>
</html>
