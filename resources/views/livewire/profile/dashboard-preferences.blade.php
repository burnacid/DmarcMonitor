<?php

use App\Models\DashboardSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    /** Empty string means "use the site-wide default". */
    public string $defaultDays = '';

    public function mount(): void
    {
        $days = Auth::user()->dashboard_default_days;

        $this->defaultDays = $days !== null && DashboardSetting::offers($days) ? (string) $days : '';
    }

    /**
     * Save the period the dashboard opens with for the current user.
     */
    public function updateDashboardPreferences(): void
    {
        $this->validate([
            'defaultDays' => ['nullable', Rule::in(array_map('strval', DashboardSetting::dayRanges()))],
        ]);

        Auth::user()->update([
            'dashboard_default_days' => $this->defaultDays === '' ? null : (int) $this->defaultDays,
        ]);

        $this->dispatch('dashboard-preferences-updated');
    }

    public function with(): array
    {
        return [
            'dayRanges' => DashboardSetting::dayRanges(),
            'siteDefaultDays' => DashboardSetting::defaultDays(),
        ];
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Dashboard') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Choose the period the dashboard opens with.') }}
        </p>
    </header>

    <form wire:submit="updateDashboardPreferences" class="mt-6 space-y-6">
        <div>
            <x-input-label for="dashboard_default_days" :value="__('Default period')" />
            <select wire:model="defaultDays" id="dashboard_default_days" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                <option value="">{{ __('Site default (:days days)', ['days' => $siteDefaultDays]) }}</option>
                @foreach ($dayRanges as $value)
                    <option value="{{ $value }}">{{ __(':days days', ['days' => $value]) }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('defaultDays')" class="mt-2" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            <x-action-message class="me-3" on="dashboard-preferences-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </form>
</section>
