<?php

use App\Models\Domain;
use App\Models\Organisation;
use App\Services\Analytics\DomainHealthService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public int $days = 30;

    #[Url(as: 'attention')]
    public bool $needsAttention = false;

    /**
     * Organisation id, or "unassigned", whose domains are shown.
     */
    public ?string $expandedKey = null;

    public function toggleExpand(string $key): void
    {
        $this->expandedKey = $this->expandedKey === $key ? null : $key;
    }

    public function with(): array
    {
        $user = auth()->user();

        if (! in_array($this->days, [7, 30, 90], true)) {
            $this->days = 30;
        }

        $service = app(DomainHealthService::class);
        $search = trim($this->search);

        $domains = Domain::visibleTo($user)->orderBy('fqdn')->get();
        $health = $service->forDomains($domains, $this->days);
        $healthByOrganisation = $health->groupBy(fn (array $row) => $row['domain']->organisation_id ?? 'unassigned');

        $groups = Organisation::visibleTo($user)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get()
            ->map(fn (Organisation $organisation) => [
                'key' => (string) $organisation->id,
                'organisation' => $organisation,
                'name' => $organisation->name,
                'domains' => $healthByOrganisation->get($organisation->id, collect())->values(),
            ]);

        if (! $user->hasOrganisationScope() && $search === '' && $healthByOrganisation->has('unassigned')) {
            $groups->push([
                'key' => 'unassigned',
                'organisation' => null,
                'name' => __('Unassigned domains'),
                'domains' => $healthByOrganisation->get('unassigned')->values(),
            ]);
        }

        $groups = $groups
            ->map(fn (array $group) => $group + ['summary' => $service->rollUp($group['domains'])])
            ->when($this->needsAttention, fn ($groups) => $groups->filter(fn (array $group) => $group['summary']['domains_with_issues'] > 0))
            ->sort(fn (array $a, array $b) => [
                -$a['summary']['domains_with_issues'],
                $a['summary']['dmarc_pass_pct'] ?? 101,
                $a['name'],
            ] <=> [
                -$b['summary']['domains_with_issues'],
                $b['summary']['dmarc_pass_pct'] ?? 101,
                $b['name'],
            ])
            ->values();

        return [
            'groups' => $groups,
            'totals' => $service->rollUp($health->values()),
            'canManage' => $user->canManage(),
        ];
    }

    public function passBadgeClass(?string $status): string
    {
        return match ($status) {
            'good' => 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
            'warning' => 'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200',
            'critical' => 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200',
            default => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400',
        };
    }
}; ?>

<div>
    @php
        $cell = 'px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400';
        $th = 'px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider';
    @endphp

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Overview') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 max-sm:px-4">
                @foreach ([
                    ['label' => __('Domains'), 'value' => number_format($totals['domains'])],
                    ['label' => __('Need attention'), 'value' => number_format($totals['domains_with_issues'])],
                    ['label' => __('DMARC pass rate (:days d)', ['days' => $days]), 'value' => $totals['dmarc_pass_pct'] !== null ? $totals['dmarc_pass_pct'].'%' : '—'],
                    ['label' => __('Enforced (p=reject)'), 'value' => number_format($totals['policies']['reject'] ?? 0).' / '.number_format($totals['domains'])],
                ] as $tile)
                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4">
                        <div class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $tile['value'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-end gap-4 max-sm:px-4">
                <div class="flex-1 min-w-[12rem] max-w-sm">
                    <x-input-label for="overview_search" :value="__('Search')" />
                    <x-text-input wire:model.live.debounce.400ms="search" id="overview_search" type="search" :placeholder="__('Organisation name')" class="mt-1 block w-full text-sm" />
                </div>

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

                <label class="inline-flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300">
                    <input wire:model.live="needsAttention" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    {{ __('Only needing attention') }}
                </label>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="{{ $th }}">{{ __('Organisation') }}</th>
                            <th class="{{ $th }}">{{ __('Domains') }}</th>
                            <th class="{{ $th }}">{{ __('Messages') }}</th>
                            <th class="{{ $th }}">{{ __('DMARC pass') }}</th>
                            <th class="{{ $th }}">{{ __('Policies') }}</th>
                            <th class="{{ $th }}">{{ __('Attention') }}</th>
                            <th class="{{ $th }}">{{ __('Last report') }}</th>
                            <th class="{{ $th }}">{{ __('Open alerts') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($groups as $group)
                            @php $summary = $group['summary']; @endphp
                            <tr wire:key="group-{{ $group['key'] }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Organisation') }}" class="{{ $cell }} font-medium text-gray-900 dark:text-gray-100">
                                    <button type="button" wire:click="toggleExpand('{{ $group['key'] }}')" class="inline-flex items-center gap-2 hover:text-indigo-600 dark:hover:text-indigo-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500 transition-transform {{ $expandedKey === $group['key'] ? 'rotate-90' : '' }}">
                                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                        </svg>
                                        <span>{{ $group['name'] }}</span>
                                    </button>
                                </td>
                                <td data-label="{{ __('Domains') }}" class="{{ $cell }} text-gray-500 dark:text-gray-400">{{ $summary['domains'] }}</td>
                                <td data-label="{{ __('Messages') }}" class="{{ $cell }} text-gray-500 dark:text-gray-400">{{ number_format($summary['total']) }}</td>
                                <td data-label="{{ __('DMARC pass') }}" class="{{ $cell }}">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->passBadgeClass($summary['pass_status']) }}">
                                        {{ $summary['dmarc_pass_pct'] !== null ? $summary['dmarc_pass_pct'].'%' : __('No data') }}
                                    </span>
                                </td>
                                <td data-label="{{ __('Policies') }}" class="{{ $cell }}">
                                    <span class="inline-flex flex-wrap gap-1 max-md:justify-end">
                                        @forelse ($summary['policies'] as $policy => $count)
                                            <x-policy-badge :policy="$policy === 'missing' ? null : $policy">{{ $count }} × {{ $policy === 'missing' ? __('none published') : $policy }}</x-policy-badge>
                                        @empty
                                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('No domains') }}</span>
                                        @endforelse
                                    </span>
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
                                <td data-label="{{ __('Last report') }}" class="{{ $cell }} text-sm text-gray-500 dark:text-gray-400" title="{{ $summary['last_report_at']?->toDayDateTimeString() }}">
                                    {{ $summary['last_report_at']?->diffForHumans() ?? __('Never') }}
                                </td>
                                <td data-label="{{ __('Open alerts') }}" class="{{ $cell }} text-sm">
                                    @if ($summary['open_alerts'] > 0 && $canManage)
                                        <a href="{{ route('admin.alert-events') }}" wire:navigate class="font-medium text-red-600 dark:text-red-400 hover:underline">{{ $summary['open_alerts'] }}</a>
                                    @else
                                        <span @class(['font-medium text-red-600 dark:text-red-400' => $summary['open_alerts'] > 0, 'text-gray-400 dark:text-gray-500' => $summary['open_alerts'] === 0])>{{ $summary['open_alerts'] }}</span>
                                    @endif
                                </td>
                            </tr>

                            @if ($expandedKey === $group['key'])
                                <tr wire:key="group-{{ $group['key'] }}-details" class="max-md:block">
                                    <td colspan="8" class="bg-gray-50 dark:bg-gray-900/50 px-6 py-4 max-md:block max-md:px-3 max-md:py-3 max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:mt-2">
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 mb-3 text-sm">
                                            @if ($group['organisation'])
                                                <a href="{{ route('dashboard', ['organisation' => $group['organisation']->id, 'days' => $days]) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Open dashboard') }}</a>
                                                @if ($canManage)
                                                    <a href="{{ route('admin.domains', ['organisation' => $group['organisation']->id]) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Manage domains') }}</a>
                                                    <a href="{{ route('admin.domains', ['organisation' => $group['organisation']->id, 'bulk' => 1]) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Add domains') }}</a>
                                                @endif
                                            @endif
                                        </div>

                                        @if ($group['domains']->isEmpty())
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This organisation has no domains yet.') }}</p>
                                        @else
                                            <table class="min-w-full text-sm">
                                                <thead class="max-md:hidden">
                                                    <tr class="text-left text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                                        <th class="py-2 pr-4 font-medium">{{ __('Domain') }}</th>
                                                        <th class="py-2 pr-4 font-medium">{{ __('Messages') }}</th>
                                                        <th class="py-2 pr-4 font-medium">{{ __('DMARC pass') }}</th>
                                                        <th class="py-2 pr-4 font-medium">{{ __('Policy') }}</th>
                                                        <th class="py-2 pr-4 font-medium">{{ __('Last report') }}</th>
                                                        <th class="py-2 font-medium">{{ __('Issues') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                                    @foreach ($group['domains'] as $row)
                                                        <tr wire:key="overview-domain-{{ $row['domain']->id }}" class="max-md:block max-md:py-2">
                                                            <td class="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100 max-md:block">
                                                                @if ($canManage)
                                                                    <a href="{{ route('admin.domains', ['search' => $row['domain']->fqdn]) }}" wire:navigate class="hover:text-indigo-600 dark:hover:text-indigo-400">{{ $row['domain']->fqdn }}</a>
                                                                @else
                                                                    {{ $row['domain']->fqdn }}
                                                                @endif
                                                                @unless ($row['domain']->is_active)
                                                                    <span class="ml-1 text-xs font-normal text-gray-400 dark:text-gray-500">({{ __('inactive') }})</span>
                                                                @endunless
                                                            </td>
                                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400 max-md:inline-block">{{ number_format($row['total']) }}</td>
                                                            <td class="py-2 pr-4 max-md:inline-block">
                                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->passBadgeClass($row['pass_status']) }}">
                                                                    {{ $row['total'] > 0 ? $row['dmarc_pass_pct'].'%' : __('No data') }}
                                                                </span>
                                                            </td>
                                                            <td class="py-2 pr-4 max-md:block">
                                                                <x-policy-badge :policy="$row['policy']" />
                                                                <span class="ml-1 text-xs {{ $row['readiness']['ready'] && $row['readiness']['step'] !== 'enforced' ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-gray-500' }}">{{ \App\Services\Analytics\DomainHealthService::readinessHint($row['readiness']) }}</span>
                                                            </td>
                                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400 max-md:inline-block">{{ $row['last_report_at']?->diffForHumans() ?? __('Never') }}</td>
                                                            <td class="py-2 max-md:block">
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
                                                </tbody>
                                            </table>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">
                                    {{ $search !== '' || $needsAttention ? __('No organisations match your filters.') : __('No organisations yet.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
