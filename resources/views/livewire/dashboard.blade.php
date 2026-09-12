<?php

use App\Models\Domain;
use App\Models\Organisation;
use App\Services\Analytics\DmarcMetricsService;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $organisationId = null;

    public ?int $domainId = null;

    public int $days = 30;

    public ?string $selectedDay = null;

    private function window(): array
    {
        return [
            Carbon::now()->subDays($this->days - 1)->startOfDay(),
            Carbon::now()->endOfDay(),
        ];
    }

    /**
     * The window driving the stat tiles and sending-sources table: a single
     * day when the trend chart has been clicked to drill in, otherwise the
     * same rolling window as the trend chart.
     */
    private function effectiveWindow(): array
    {
        if ($this->selectedDay !== null) {
            $day = Carbon::parse($this->selectedDay);

            return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
        }

        return $this->window();
    }

    private function trendData(): array
    {
        [$from, $to] = $this->window();

        return app(DmarcMetricsService::class)->trend($this->domainId, $from, $to, $this->organisationId)->all();
    }

    public function updatedOrganisationId(): void
    {
        // Drop a domain selection that no longer belongs to the chosen organisation.
        if ($this->domainId !== null) {
            $domain = Domain::find($this->domainId);

            if (! $domain || $domain->organisation_id !== $this->organisationId) {
                $this->domainId = null;
            }
        }

        $this->selectedDay = null;
        $this->dispatch('trend-updated', trend: $this->trendData());
    }

    public function updatedDomainId(): void
    {
        $this->selectedDay = null;
        $this->dispatch('trend-updated', trend: $this->trendData());
    }

    public function updatedDays(): void
    {
        $this->selectedDay = null;
        $this->dispatch('trend-updated', trend: $this->trendData());
    }

    /**
     * Called when a point on the trend chart is clicked, to drill the stat
     * tiles and sending-sources table down into that single day. Clicking
     * the same day again clears the drill-down.
     */
    public function selectDay(string $date): void
    {
        $this->selectedDay = $this->selectedDay === $date ? null : $date;
    }

    public function clearSelectedDay(): void
    {
        $this->selectedDay = null;
    }

    public function with(): array
    {
        $service = app(DmarcMetricsService::class);
        [$from, $to] = $this->effectiveWindow();

        $domains = Domain::orderBy('fqdn');

        if ($this->organisationId !== null) {
            $domains->where('organisation_id', $this->organisationId);
        }

        return [
            'organisations' => Organisation::orderBy('name')->get(),
            'domains' => $domains->get(),
            'summary' => $service->summary($this->domainId, $from, $to, $this->organisationId),
            'trend' => $this->trendData(),
            'sourceGroups' => $service->groupedSourceBreakdown($this->domainId, $from, $to, $this->organisationId)->take(25),
            'hasAnyReports' => \App\Models\AggregateReport::query()->exists(),
            'windowFrom' => $from->toDateString(),
            'windowTo' => $to->toDateString(),
        ];
    }

    public function statusFor(float $pct): string
    {
        return match (true) {
            $pct >= 95 => 'good',
            $pct >= 80 => 'warning',
            default => 'critical',
        };
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Dashboard') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Filters --}}
            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="organisationId" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                    <option value="">{{ __('All organisations') }}</option>
                    @foreach ($organisations as $organisation)
                        <option value="{{ $organisation->id }}">{{ $organisation->name }}</option>
                    @endforeach
                </select>

                <select wire:model.live="domainId" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                    <option value="">{{ __('All domains') }}</option>
                    @foreach ($domains as $domain)
                        <option value="{{ $domain->id }}">{{ $domain->fqdn }}</option>
                    @endforeach
                </select>

                <div class="inline-flex rounded-md shadow-sm" role="group">
                    @foreach ([7 => '7d', 30 => '30d', 90 => '90d'] as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('days', {{ $value }})"
                            @class([
                                'px-3 py-1.5 text-sm font-medium border first:rounded-l-md last:rounded-r-md -ml-px first:ml-0',
                                'bg-indigo-600 text-white border-indigo-600' => $days === $value,
                                'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' => $days !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if (! $hasAnyReports)
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-8 text-center">
                    <p class="text-gray-600 dark:text-gray-300">{{ __('No DMARC reports have been collected yet.') }}</p>
                    <p class="mt-2 text-sm text-gray-400 dark:text-gray-500">
                        {{ __('Configure an IMAP account to start collecting aggregate reports.') }}
                        <a href="{{ route('admin.imap-accounts') }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Go to IMAP Accounts') }} &rarr;</a>
                    </p>
                </div>
            @else
                @if ($selectedDay)
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('Showing:') }}</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-100 dark:bg-indigo-900 px-3 py-1 text-xs font-medium text-indigo-800 dark:text-indigo-200">
                            {{ \Illuminate\Support\Carbon::parse($selectedDay)->format('M j, Y') }}
                            <button type="button" wire:click="clearSelectedDay" class="hover:text-indigo-900 dark:hover:text-indigo-100" aria-label="{{ __('Clear day filter') }}">&times;</button>
                        </span>
                    </div>
                @endif

                {{-- Stat tiles --}}
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Messages') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($summary['total']) }}</div>
                    </div>

                    @foreach ([['label' => 'DMARC Pass', 'key' => 'dmarc_pass_pct'], ['label' => 'SPF Pass', 'key' => 'spf_pass_pct'], ['label' => 'DKIM Pass', 'key' => 'dkim_pass_pct']] as $tile)
                        @php($status = $this->statusFor($summary[$tile['key']]))
                        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4">
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __($tile['label']) }}</div>
                            <div @class([
                                'mt-1 text-2xl font-semibold tabular-nums',
                                'text-green-600 dark:text-green-400' => $status === 'good',
                                'text-amber-500 dark:text-amber-400' => $status === 'warning',
                                'text-red-600 dark:text-red-400' => $status === 'critical',
                            ])>{{ $summary[$tile['key']] }}%</div>
                        </div>
                    @endforeach

                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Sending Sources') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($summary['distinct_sources']) }}</div>
                    </div>
                </div>

                {{-- Trend chart --}}
                <div
                    wire:ignore
                    x-data="dmarcTrendChart(@js($trend))"
                    x-init="init($el.querySelector('canvas'))"
                    class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4"
                >
                    <div class="flex items-baseline justify-between mb-3">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Pass rate over time') }}</h3>
                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('Click a day to drill in') }}</span>
                    </div>
                    <div class="relative" style="height: 280px">
                        <canvas></canvas>
                    </div>
                </div>

                {{-- Source breakdown --}}
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 px-6 pt-4">{{ __('Sending sources') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 mt-2">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domain') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Source') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('IP Addresses') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Envelope To') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Volume') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('DMARC Pass') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('SPF Pass') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('DKIM Pass') }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Enforced') }}</th>
                                    <th class="px-6 py-3"></th>
                                </tr>
                            </thead>
                            @forelse ($sourceGroups as $group)
                                @php($status = $this->statusFor($group['dmarc_pass_pct']))
                                @php($spfStatus = $this->statusFor($group['spf_pass_pct']))
                                @php($dkimStatus = $this->statusFor($group['dkim_pass_pct']))
                                @php($expandable = $group['ip_count'] > 1 || $group['envelope_count'] > 1)
                                <tbody
                                    wire:key="group-{{ $group['label'] }}"
                                    x-data="{ open: false }"
                                    class="divide-y divide-gray-200 dark:divide-gray-700"
                                >
                                    <tr
                                        @if ($expandable) @click="open = ! open" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700" @endif
                                    >
                                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $group['domain'] }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            <div class="flex items-center gap-2">
                                                @if ($expandable)
                                                    <svg :class="{ 'rotate-90': open }" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500 transition-transform shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                                    </svg>
                                                @else
                                                    <span class="inline-block w-3.5"></span>
                                                @endif
                                                <span class="whitespace-nowrap">{{ $group['label'] }}</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            @if ($group['ip_count'] > 1)
                                                <span class="group/ips relative inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap cursor-default">
                                                    {{ __(':count IP addresses', ['count' => $group['ip_count']]) }}
                                                    <span class="pointer-events-none absolute left-1/2 bottom-full z-10 mb-1.5 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-gray-900 dark:bg-gray-700 px-2 py-1 font-mono text-xs text-white shadow-lg group-hover/ips:block">
                                                        @foreach ($group['ips'] as $ip)
                                                            <span class="block"><x-country-flag :code="$ip['country']" /> {{ $ip['source_ip'] }}</span>
                                                        @endforeach
                                                    </span>
                                                </span>
                                            @else
                                                <span class="font-mono text-xs"><x-country-flag :code="$group['ips'][0]['country'] ?? null" /> {{ $group['ips'][0]['source_ip'] ?? '' }}</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            @if ($group['envelope_count'] > 1)
                                                {{ __(':count domains', ['count' => $group['envelope_count']]) }}
                                            @else
                                                {{ $group['envelopes'][0]['domain'] ?? '—' }}
                                            @endif
                                        </td>
                                        <td class="px-6 py-3 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($group['total']) }}</td>
                                        <td class="px-6 py-3 whitespace-nowrap text-right text-sm tabular-nums">
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $status === 'good',
                                                'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => $status === 'warning',
                                                'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $status === 'critical',
                                            ])>{{ $group['dmarc_pass_pct'] }}%</span>
                                        </td>
                                        <td class="px-6 py-3 whitespace-nowrap text-right text-sm tabular-nums">
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $spfStatus === 'good',
                                                'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => $spfStatus === 'warning',
                                                'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $spfStatus === 'critical',
                                            ])>{{ $group['spf_pass_pct'] }}%</span>
                                        </td>
                                        <td class="px-6 py-3 whitespace-nowrap text-right text-sm tabular-nums">
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $dkimStatus === 'good',
                                                'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => $dkimStatus === 'warning',
                                                'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $dkimStatus === 'critical',
                                            ])>{{ $group['dkim_pass_pct'] }}%</span>
                                        </td>
                                        <td class="px-6 py-3 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($group['enforced']) }}</td>
                                        <td class="px-6 py-3 whitespace-nowrap text-right text-sm">
                                            <a
                                                href="{{ route('reports.index', ['domain_id' => $group['domain_id'], 'ip' => $group['ips']->pluck('source_ip')->implode(','), 'from' => $windowFrom, 'to' => $windowTo]) }}"
                                                wire:navigate
                                                @click.stop
                                                class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300"
                                            >{{ __('Reports') }}</a>
                                        </td>
                                    </tr>

                                    @if ($expandable)
                                        @forelse ($group['envelopes'] as $envelope)
                                            @php($envelopeStatus = $this->statusFor($envelope['dmarc_pass_pct']))
                                            @php($envelopeSpfStatus = $this->statusFor($envelope['spf_pass_pct']))
                                            @php($envelopeDkimStatus = $this->statusFor($envelope['dkim_pass_pct']))
                                            <tr x-show="open" x-cloak class="bg-gray-100 dark:bg-gray-900/40">
                                                <td class="px-6 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $group['domain'] }}</td>
                                                <td class="pl-14 pr-6 py-2 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $group['label'] }}</td>
                                                <td class="px-6 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                                    @if (count($envelope['ips']) > 1)
                                                        <span class="group relative inline-flex items-center rounded-full bg-gray-200 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap cursor-default">
                                                            {{ __(':count IP addresses', ['count' => count($envelope['ips'])]) }}
                                                            <span class="pointer-events-none absolute left-1/2 bottom-full z-10 mb-1.5 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-gray-900 dark:bg-gray-700 px-2 py-1 font-mono text-xs text-white shadow-lg group-hover:block">
                                                                @foreach ($envelope['ips'] as $ip)
                                                                    <span class="block"><x-country-flag :code="$group['country_by_ip'][$ip] ?? null" /> {{ $ip }}</span>
                                                                @endforeach
                                                            </span>
                                                        </span>
                                                    @else
                                                        <span class="font-mono"><x-country-flag :code="$group['country_by_ip'][$envelope['ips'][0]] ?? null" /> {{ $envelope['ips'][0] ?? '' }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-6 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $envelope['domain'] }}</td>
                                                <td class="px-6 py-2 whitespace-nowrap text-right text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($envelope['total']) }}</td>
                                                <td class="px-6 py-2 whitespace-nowrap text-right text-xs tabular-nums">
                                                    <span @class([
                                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                        'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $envelopeStatus === 'good',
                                                        'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => $envelopeStatus === 'warning',
                                                        'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $envelopeStatus === 'critical',
                                                    ])>{{ $envelope['dmarc_pass_pct'] }}%</span>
                                                </td>
                                                <td class="px-6 py-2 whitespace-nowrap text-right text-xs tabular-nums">
                                                    <span @class([
                                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                        'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $envelopeSpfStatus === 'good',
                                                        'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => $envelopeSpfStatus === 'warning',
                                                        'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $envelopeSpfStatus === 'critical',
                                                    ])>{{ $envelope['spf_pass_pct'] }}%</span>
                                                </td>
                                                <td class="px-6 py-2 whitespace-nowrap text-right text-xs tabular-nums">
                                                    <span @class([
                                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                        'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200' => $envelopeDkimStatus === 'good',
                                                        'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200' => $envelopeDkimStatus === 'warning',
                                                        'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200' => $envelopeDkimStatus === 'critical',
                                                    ])>{{ $envelope['dkim_pass_pct'] }}%</span>
                                                </td>
                                                <td class="px-6 py-2 whitespace-nowrap text-right text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ number_format($envelope['enforced']) }}</td>
                                                <td class="px-6 py-2 whitespace-nowrap text-right text-xs">
                                                    <a
                                                        href="{{ route('reports.index', ['domain_id' => $group['domain_id'], 'ip' => implode(',', $envelope['ips']), 'from' => $windowFrom, 'to' => $windowTo]) }}"
                                                        wire:navigate
                                                        class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300"
                                                    >{{ __('Reports') }}</a>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr x-show="open" x-cloak class="bg-gray-100 dark:bg-gray-900/40">
                                                <td class="px-6 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $group['domain'] }}</td>
                                                <td class="pl-14 pr-6 py-2 text-xs text-gray-400 dark:text-gray-500" colspan="8">
                                                    {{ __('No envelope-to data recorded.') }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    @endif
                                </tbody>
                            @empty
                                <tbody>
                                    <tr>
                                        <td colspan="10" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No sending sources in this window.') }}</td>
                                    </tr>
                                </tbody>
                            @endforelse
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('dmarcTrendChart', (initialData) => {
        // Kept as a plain closure variable, NOT a returned property: Alpine deep-wraps
        // every property of an x-data object in a reactive Proxy, and Chart.js instances
        // are full of circular internal references (chart <-> scales <-> controllers),
        // which sends that Proxy into infinite recursion the moment anything touches it.
        let chart = null;

        return {
            colors() {
                const dark = document.documentElement.classList.contains('dark');
                return {
                    dmarc: dark ? '#3987e5' : '#2a78d6',
                    spf: dark ? '#d95926' : '#eb6834',
                    dkim: dark ? '#199e70' : '#1baf7a',
                    grid: dark ? 'rgba(148, 163, 184, 0.12)' : 'rgba(100, 116, 139, 0.15)',
                    ink: dark ? '#c3c2b7' : '#52514e',
                };
            },

            init(canvas) {
                if (!canvas) return;

                const existing = Chart.getChart(canvas);
                if (existing) existing.destroy();

                const c = this.colors();

                chart = new Chart(canvas, {
                    type: 'line',
                    data: this.buildData(initialData, c),
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        onClick: (evt, elements) => {
                            if (!elements.length) return;

                            const date = chart.data.labels[elements[0].index];
                            this.$wire.call('selectDay', date);
                        },
                        onHover: (evt, elements) => {
                            evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                        },
                        scales: {
                            y: {
                                min: 0,
                                max: 100,
                                ticks: { color: c.ink, stepSize: 25, callback: (v) => v + '%' },
                                grid: { color: c.grid },
                                border: { display: false },
                            },
                            x: {
                                ticks: { color: c.ink },
                                grid: { display: false },
                                border: { display: false },
                            },
                        },
                        plugins: {
                            legend: {
                                labels: { color: c.ink, usePointStyle: true, pointStyle: 'line' },
                            },
                            tooltip: {
                                callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y}%` },
                            },
                        },
                    },
                });

                window.addEventListener('theme-changed', () => this.restyle());

                window.Livewire.on('trend-updated', (event) => this.update(event.trend));
            },

            buildData(data, c) {
                return {
                    labels: data.map((row) => row.date),
                    datasets: [
                        { label: 'DMARC', data: data.map((row) => row.dmarc_pass_pct), borderColor: c.dmarc, backgroundColor: c.dmarc, tension: 0.25, borderWidth: 2, pointRadius: 2 },
                        { label: 'SPF', data: data.map((row) => row.spf_pass_pct), borderColor: c.spf, backgroundColor: c.spf, tension: 0.25, borderWidth: 2, pointRadius: 2 },
                        { label: 'DKIM', data: data.map((row) => row.dkim_pass_pct), borderColor: c.dkim, backgroundColor: c.dkim, tension: 0.25, borderWidth: 2, pointRadius: 2 },
                    ],
                };
            },

            update(data) {
                if (!chart) return;
                const c = this.colors();
                const fresh = this.buildData(data, c);
                chart.data.labels = fresh.labels;
                chart.data.datasets.forEach((ds, i) => { ds.data = fresh.datasets[i].data; });
                chart.update();
            },

            restyle() {
                if (!chart) return;
                const c = this.colors();
                chart.options.scales.y.ticks.color = c.ink;
                chart.options.scales.y.grid.color = c.grid;
                chart.options.scales.x.ticks.color = c.ink;
                chart.options.plugins.legend.labels.color = c.ink;
                chart.data.datasets[0].borderColor = c.dmarc;
                chart.data.datasets[0].backgroundColor = c.dmarc;
                chart.data.datasets[1].borderColor = c.spf;
                chart.data.datasets[1].backgroundColor = c.spf;
                chart.data.datasets[2].borderColor = c.dkim;
                chart.data.datasets[2].backgroundColor = c.dkim;
                chart.update();
            },
        };
    });
</script>
@endscript
