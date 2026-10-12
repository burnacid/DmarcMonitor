<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta http-equiv="refresh" content="300">

        <title>{{ __('Overview') }} · {{ config('app.name', 'DMARC Monitor') }}</title>

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
            $cell = 'px-6 py-3 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400';
            $th = 'px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider';
        @endphp

        <div class="min-h-screen flex flex-col bg-gray-100 dark:bg-gray-900">
            <header class="bg-white dark:bg-gray-800 shadow">
                <div class="max-w-[100rem] mx-auto py-4 px-4 sm:px-6 lg:px-8 flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        @if ($logo)
                            <img src="{{ $logo }}" alt="" class="h-9 w-auto">
                        @else
                            <x-application-logo class="h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                        @endif
                        <h1 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Overview') }}</h1>
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
                    </div>
                </div>
            </header>

            <main class="flex-1 py-6">
                <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-4">
                    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 max-sm:px-4">
                        @foreach ([
                            ['label' => __('Domains'), 'value' => number_format($totals['domains'])],
                            ['label' => __('Need attention'), 'value' => number_format($totals['domains_with_issues'])],
                            ['label' => __('DMARC pass rate (:days d)', ['days' => $days]), 'value' => $totals['dmarc_pass_pct'] !== null ? $totals['dmarc_pass_pct'].'%' : '—'],
                            ['label' => __('Enforced (p=reject)'), 'value' => number_format($totals['policies']['reject'] ?? 0).' / '.number_format($totals['domains'])],
                            ['label' => __('Open alerts'), 'value' => number_format($totals['open_alerts'])],
                        ] as $tile)
                            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4">
                                <div class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</div>
                                <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $tile['value'] }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                            <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                                <tr>
                                    <th class="{{ $th }}">{{ __('Organisation / domain') }}</th>
                                    <th class="{{ $th }}">{{ __('Messages') }}</th>
                                    <th class="{{ $th }}">{{ __('DMARC pass') }}</th>
                                    <th class="{{ $th }}">{{ __('Policy') }}</th>
                                    <th class="{{ $th }}">{{ __('Last report') }}</th>
                                    <th class="{{ $th }}">{{ __('Open alerts') }}</th>
                                    <th class="{{ $th }}">{{ __('Issues') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                                @forelse ($groups as $group)
                                    @php $summary = $group['summary']; @endphp
                                    <tr class="bg-gray-50 dark:bg-gray-900/50 max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                        <td data-label="{{ __('Organisation') }}" class="{{ $cell }} font-semibold text-gray-900 dark:text-gray-100">
                                            {{ $group['name'] }}
                                            <span class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">{{ trans_choice(':count domain|:count domains', $summary['domains']) }}</span>
                                        </td>
                                        <td data-label="{{ __('Messages') }}" class="{{ $cell }} text-gray-500 dark:text-gray-400">{{ number_format($summary['total']) }}</td>
                                        <td data-label="{{ __('DMARC pass') }}" class="{{ $cell }}">
                                            <x-pass-badge :status="$summary['pass_status']">
                                                {{ $summary['dmarc_pass_pct'] !== null ? $summary['dmarc_pass_pct'].'%' : __('No data') }}
                                            </x-pass-badge>
                                        </td>
                                        <td data-label="{{ __('Policy') }}" class="{{ $cell }}">
                                            <span class="inline-flex flex-wrap gap-1 max-md:justify-end">
                                                @foreach ($summary['policies'] as $policy => $count)
                                                    <x-policy-badge :policy="$policy === 'missing' ? null : $policy">{{ $count }} × {{ $policy === 'missing' ? __('none published') : $policy }}</x-policy-badge>
                                                @endforeach
                                            </span>
                                        </td>
                                        <td data-label="{{ __('Last report') }}" class="{{ $cell }} text-sm text-gray-500 dark:text-gray-400">{{ $summary['last_report_at']?->diffForHumans() ?? __('Never') }}</td>
                                        <td data-label="{{ __('Open alerts') }}" class="{{ $cell }} text-sm">
                                            <span @class(['font-medium text-red-600 dark:text-red-400' => $summary['open_alerts'] > 0, 'text-gray-400 dark:text-gray-500' => $summary['open_alerts'] === 0])>{{ $summary['open_alerts'] }}</span>
                                        </td>
                                        <td data-label="{{ __('Attention') }}" class="{{ $cell }} text-sm">
                                            @if ($summary['domains_with_issues'] > 0)
                                                <span class="text-red-600 dark:text-red-400 font-medium">{{ trans_choice(':count domain|:count domains', $summary['domains_with_issues']) }}</span>
                                            @elseif ($summary['domains'] > 0)
                                                <span class="text-green-600 dark:text-green-400">{{ __('All good') }}</span>
                                            @else
                                                <span class="text-gray-400 dark:text-gray-500">—</span>
                                            @endif
                                        </td>
                                    </tr>

                                    @foreach ($group['domains'] as $row)
                                        <tr class="text-sm max-md:block max-md:px-3 max-md:py-2">
                                            <td class="px-6 py-2 pl-10 font-medium text-gray-900 dark:text-gray-100 max-md:block max-md:p-0">
                                                {{ $row['domain']->fqdn }}
                                                @unless ($row['domain']->is_active)
                                                    <span class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">({{ __('inactive') }})</span>
                                                @endunless
                                            </td>
                                            <td class="px-6 py-2 text-gray-500 dark:text-gray-400 max-md:inline-block max-md:p-0 max-md:pr-3">{{ number_format($row['total']) }}</td>
                                            <td class="px-6 py-2 max-md:inline-block max-md:p-0 max-md:pr-3">
                                                <x-pass-badge :status="$row['pass_status']">
                                                    {{ $row['total'] > 0 ? $row['dmarc_pass_pct'].'%' : __('No data') }}
                                                </x-pass-badge>
                                            </td>
                                            <td class="px-6 py-2 max-md:inline-block max-md:p-0 max-md:pr-3"><x-policy-badge :policy="$row['policy']" /></td>
                                            <td class="px-6 py-2 text-gray-500 dark:text-gray-400 max-md:inline-block max-md:p-0 max-md:pr-3">{{ $row['last_report_at']?->diffForHumans() ?? __('Never') }}</td>
                                            <td class="px-6 py-2 max-md:hidden">
                                                <span @class(['font-medium text-red-600 dark:text-red-400' => $row['open_alerts'] > 0, 'text-gray-400 dark:text-gray-500' => $row['open_alerts'] === 0])>{{ $row['open_alerts'] }}</span>
                                            </td>
                                            <td class="px-6 py-2 max-md:block max-md:p-0 max-md:pt-1">
                                                <span class="inline-flex flex-wrap gap-1">
                                                    @foreach ($row['issues'] as $issue)
                                                        <span class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/40 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:text-red-300">{{ \App\Services\Analytics\DomainHealthService::issueLabel($issue) }}</span>
                                                    @endforeach
                                                    @foreach ($row['remarks'] as $remark)
                                                        <span title="{{ \App\Services\Analytics\DomainHealthService::remarkDescription($remark) }}" class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:text-gray-300">{{ \App\Services\Analytics\DomainHealthService::remarkLabel($remark) }}</span>
                                                    @endforeach
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No organisations yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
