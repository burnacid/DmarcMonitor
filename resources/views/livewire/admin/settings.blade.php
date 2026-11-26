<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * One page for the installation-wide settings, one tab per area. Each tab
 * is its own component, so only the open tab is loaded.
 */
new #[Layout('layouts.app')] class extends Component
{
    /** Tab key => component rendered in it. */
    public const array TABS = [
        'dashboard' => 'admin.dashboard-settings',
        'public-dashboard' => 'admin.public-dashboard',
        'report-branding' => 'admin.report-branding',
    ];

    #[Url]
    public string $tab = 'dashboard';

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'dashboard';
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'dashboard';
    }

    public function with(): array
    {
        return [
            'tabs' => [
                'dashboard' => __('Dashboard'),
                'public-dashboard' => __('Public Dashboard'),
                'report-branding' => __('Report Branding'),
            ],
            'component' => self::TABS[$this->tab],
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Settings') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-tabs :tabs="$tabs" :active="$tab" />

            <livewire:dynamic-component :is="$component" :key="$tab" />
        </div>
    </div>
</div>
