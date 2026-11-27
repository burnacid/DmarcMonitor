<?php

use App\Models\DashboardSetting;
use App\Support\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function toggleDayRange(int $days): void
    {
        $wasOffered = DashboardSetting::offers($days);
        $setting = DashboardSetting::toggleDayRange($days);

        if ($setting === null) {
            return;
        }

        AuditLogger::record(
            action: $wasOffered ? 'dashboard.day_range_removed' : 'dashboard.day_range_added',
            description: ($wasOffered ? 'Removed' : 'Added').' the '.$days.' day period '.($wasOffered ? 'from' : 'to').' the dashboard',
            subject: $setting,
        );
    }

    public function setDefaultDays(int $days): void
    {
        $setting = DashboardSetting::setDefaultDays($days);

        if ($setting === null) {
            return;
        }

        AuditLogger::record(
            action: 'dashboard.default_days_changed',
            description: 'Set the default period of the dashboard to '.$days.' days',
            subject: $setting,
        );
    }

    public function setPassRateDays(int $days): void
    {
        $setting = DashboardSetting::setPassRateDays($days);

        if ($setting === null) {
            return;
        }

        AuditLogger::record(
            action: 'dashboard.pass_rate_days_changed',
            description: 'Set the low pass rate period to '.$days.' days',
            subject: $setting,
        );
    }

    public function toggleIgnoreMissingReports(): void
    {
        $setting = DashboardSetting::toggleIgnoreMissingReports();
        $isOn = $setting->ignore_missing_reports_when_reporting_here;

        AuditLogger::record(
            action: $isOn ? 'dashboard.ignore_missing_reports_enabled' : 'dashboard.ignore_missing_reports_disabled',
            description: ($isOn ? 'Stopped' : 'Started').' flagging missing reports for domains whose DMARC record reports here',
            subject: $setting,
        );
    }

    public function with(): array
    {
        return [
            'dayRanges' => DashboardSetting::dayRanges(),
            'defaultDays' => DashboardSetting::defaultDays(),
            'passRateDays' => DashboardSetting::passRateDays(),
            'ignoreMissingReports' => DashboardSetting::ignoresMissingReportsWhenReportingHere(),
        ];
    }
}; ?>

<div>
    <div class="space-y-4">
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
            <div class="space-y-2">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ __('Periods') }}</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (DashboardSetting::AVAILABLE_DAY_RANGES as $value)
                        @php($isOffered = in_array($value, $dayRanges, true))
                        <button type="button" wire:click="toggleDayRange({{ $value }})" aria-pressed="{{ $isOffered ? 'true' : 'false' }}" @class([
                            'px-3 py-1 text-xs font-medium border rounded-md',
                            'bg-indigo-600 text-white border-indigo-600' => $isOffered,
                            'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' => ! $isOffered,
                        ])>{{ $value }}d</button>
                    @endforeach
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('The periods users can pick on the dashboard. At least one stays on.') }}</p>
            </div>

            <div class="space-y-2">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ __('Default period') }}</p>
                <span class="inline-flex rounded-md shadow-sm" role="group">
                    @foreach ($dayRanges as $value)
                        <button type="button" wire:click="setDefaultDays({{ $value }})" @class([
                            'px-3 py-1 text-xs font-medium border first:rounded-l-md last:rounded-r-md -ml-px first:ml-0',
                            'bg-indigo-600 text-white border-indigo-600' => $defaultDays === $value,
                            'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' => $defaultDays !== $value,
                        ])>{{ $value }}d</button>
                    @endforeach
                </span>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('The period the dashboard opens with. Users can choose their own default on their profile page.') }}</p>
            </div>

            <div class="space-y-2">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ __('Low pass rate period') }}</p>
                <span class="inline-flex rounded-md shadow-sm" role="group">
                    @foreach (DashboardSetting::PASS_RATE_DAY_RANGES as $value)
                        <button type="button" wire:click="setPassRateDays({{ $value }})" @class([
                            'px-3 py-1 text-xs font-medium border first:rounded-l-md last:rounded-r-md -ml-px first:ml-0',
                            'bg-indigo-600 text-white border-indigo-600' => $passRateDays === $value,
                            'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' => $passRateDays !== $value,
                        ])>{{ $value }}d</button>
                    @endforeach
                </span>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('How many days of reports the "Low pass rate" flag on the Domains page and in the management report looks at. The Overview page uses the period picked there.') }}</p>
            </div>

            <div class="space-y-2">
                <p class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ __('Ignore missing reports for domains reporting here') }}:</span>
                    @if ($ignoreMissingReports)
                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('On') }}</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-800 dark:text-gray-200">{{ __('Off') }}</span>
                    @endif
                    <button type="button" wire:click="toggleIgnoreMissingReports" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ $ignoreMissingReports ? __('Turn off') : __('Turn on') }}</button>
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('When on, a domain whose DMARC record sends its reports to an address this app monitors is not flagged with "No recent reports" when no report arrived for :days days. It shows the remark "Quiet, reports here" instead. If the record stops pointing here, the flag comes back.', ['days' => \App\Services\Analytics\DomainHealthService::NO_REPORTS_DAYS]) }}</p>
            </div>
        </div>
    </div>
</div>
