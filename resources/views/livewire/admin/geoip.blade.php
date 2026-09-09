<?php

use App\Models\AggregateReportRecord;
use App\Models\IpEnrichmentCache;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $path = config('geoip.mmdb_path');
        $exists = $path && is_file($path);

        $countryPath = config('geoip.country_mmdb_path');
        $countryExists = $countryPath && is_file($countryPath);

        $totalRecords = AggregateReportRecord::count();
        $enrichedRecords = AggregateReportRecord::whereNotNull('enriched_at')->count();

        return [
            'path' => $path,
            'exists' => $exists,
            'sizeBytes' => $exists ? filesize($path) : null,
            'modifiedAt' => $exists ? \Illuminate\Support\Carbon::createFromTimestamp(filemtime($path)) : null,
            'countryPath' => $countryPath,
            'countryExists' => $countryExists,
            'countrySizeBytes' => $countryExists ? filesize($countryPath) : null,
            'countryModifiedAt' => $countryExists ? \Illuminate\Support\Carbon::createFromTimestamp(filemtime($countryPath)) : null,
            'licenseKeyConfigured' => filled(config('geoip.license_key')),
            'totalRecords' => $totalRecords,
            'enrichedRecords' => $enrichedRecords,
            'cachedIps' => IpEnrichmentCache::count(),
            'failedLookups' => IpEnrichmentCache::where('lookup_failed', true)->count(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('IP Enrichment (GeoIP)') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">{{ __('GeoLite2 ASN database') }}</h3>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</dt>
                        <dd class="mt-1">
                            @if ($exists)
                                <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Installed') }}</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200">{{ __('Not installed') }}</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('MaxMind license key') }}</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">
                            {{ $licenseKeyConfigured ? __('Configured') : __('Not set (MAXMIND_LICENSE_KEY)') }}
                        </dd>
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Path') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-gray-900 dark:text-gray-100 break-all">{{ $path }}</dd>
                    </div>

                    @if ($exists)
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('File size') }}</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ number_format($sizeBytes / 1024 / 1024, 1) }} MB</dd>
                        </div>

                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Last updated') }}</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $modifiedAt->diffForHumans() }}</dd>
                        </div>
                    @endif
                </dl>

                @unless ($exists)
                    <div class="mt-6 rounded-md bg-amber-50 dark:bg-amber-950 p-4 text-sm text-amber-800 dark:text-amber-200">
                        <p class="font-medium">{{ __('ASN/organisation enrichment is disabled until this file is installed.') }}</p>
                        <p class="mt-2">{{ __('Reverse DNS hostnames still resolve without it.') }}</p>
                        <ol class="mt-3 list-decimal list-inside space-y-1">
                            <li>{{ __('Create a free MaxMind account and generate a license key.') }}</li>
                            <li>{{ __('Set MAXMIND_LICENSE_KEY in your .env file.') }}</li>
                            <li>{{ __('Use MaxMind\'s geoipupdate tool to download GeoLite2-ASN.mmdb to:') }} <span class="font-mono">{{ $path }}</span></li>
                            <li>{{ __('Schedule geoipupdate to run weekly so the database stays current.') }}</li>
                        </ol>
                    </div>
                @endunless
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">{{ __('GeoLite2 Country database') }}</h3>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</dt>
                        <dd class="mt-1">
                            @if ($countryExists)
                                <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Installed') }}</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200">{{ __('Not installed') }}</span>
                            @endif
                        </dd>
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Path') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-gray-900 dark:text-gray-100 break-all">{{ $countryPath }}</dd>
                    </div>

                    @if ($countryExists)
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('File size') }}</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ number_format($countrySizeBytes / 1024 / 1024, 1) }} MB</dd>
                        </div>

                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Last updated') }}</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $countryModifiedAt->diffForHumans() }}</dd>
                        </div>
                    @endif
                </dl>

                @unless ($countryExists)
                    <div class="mt-6 rounded-md bg-amber-50 dark:bg-amber-950 p-4 text-sm text-amber-800 dark:text-amber-200">
                        <p class="font-medium">{{ __('Country flags on the dashboard are disabled until this file is installed.') }}</p>
                        <ol class="mt-3 list-decimal list-inside space-y-1">
                            <li>{{ __('Create a free MaxMind account and generate a license key.') }}</li>
                            <li>{{ __('Set MAXMIND_LICENSE_KEY in your .env file.') }}</li>
                            <li>{{ __('Use MaxMind\'s geoipupdate tool to download GeoLite2-Country.mmdb to:') }} <span class="font-mono">{{ $countryPath }}</span></li>
                            <li>{{ __('Schedule geoipupdate to run weekly so the database stays current.') }}</li>
                        </ol>
                    </div>
                @endunless
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">{{ __('Enrichment coverage') }}</h3>

                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Records') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($totalRecords) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Enriched') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($enrichedRecords) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Cached IPs') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($cachedIps) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Failed lookups') }}</dt>
                        <dd class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($failedLookups) }}</dd>
                    </div>
                </dl>

                @if ($totalRecords > $enrichedRecords)
                    <div class="mt-6 rounded-md bg-gray-50 dark:bg-gray-700 p-4 text-sm text-gray-600 dark:text-gray-300">
                        {{ __(':count record(s) not yet enriched. New reports enrich automatically in the background; to backfill existing ones, run:', ['count' => number_format($totalRecords - $enrichedRecords)]) }}
                        <code class="block mt-2 font-mono text-xs bg-gray-900 text-gray-100 rounded px-3 py-2">php artisan dmarc:backfill-enrichment</code>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
