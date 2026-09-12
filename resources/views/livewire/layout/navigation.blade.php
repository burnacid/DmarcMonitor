<?php

use App\Livewire\Actions\Logout;
use App\Models\AlertEvent;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function with(): array
    {
        return [
            'openAlertsCount' => auth()->user()->canManage() ? AlertEvent::whereNull('resolved_at')->count() : 0,
        ];
    }
}; ?>

<nav
    x-data="{
        open: false,
        dark: localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches),
        toggleDark() {
            this.dark = ! this.dark;
            localStorage.theme = this.dark ? 'dark' : 'light';
            document.documentElement.classList.toggle('dark', this.dark);
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark: this.dark } }));
        },
    }"
    wire:poll.60s="$refresh"
    class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700"
>
    <!-- Primary Navigation Menu -->
    <div class="max-w-[100rem] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" wire:navigate>
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-100" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    <x-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.*')" wire:navigate>
                        {{ __('Reports') }}
                    </x-nav-link>
                    <x-nav-link :href="route('forensic-reports.index')" :active="request()->routeIs('forensic-reports.*')" wire:navigate>
                        {{ __('Forensic Reports') }}
                    </x-nav-link>
                    @if (auth()->user()->canManage())
                        <x-nav-link :href="route('admin.alert-events')" :active="request()->routeIs('admin.alert-events')" wire:navigate>
                            {{ __('Alerts') }}
                            @if ($openAlertsCount > 0)
                                <span class="ml-1.5 inline-flex items-center justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-medium leading-none text-white">{{ $openAlertsCount > 99 ? '99+' : $openAlertsCount }}</span>
                            @endif
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 space-x-2">
                @php
                    $settingsActive = request()->routeIs(['admin.organisations', 'admin.domains', 'admin.imap-accounts', 'admin.geoip', 'admin.alert-rules', 'admin.users']);
                @endphp
                @if (auth()->user()->canManage() || auth()->user()->isAdmin())
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button
                                type="button"
                                @class([
                                    'p-2 rounded-md focus:outline-none transition ease-in-out duration-150',
                                    'text-gray-700 dark:text-gray-100 bg-gray-100 dark:bg-gray-700' => $settingsActive,
                                    'text-gray-400 dark:text-gray-300 hover:text-gray-500 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700' => ! $settingsActive,
                                ])
                                aria-label="{{ __('Settings') }}"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a7.48 7.48 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            @if (auth()->user()->canManage())
                                <x-dropdown-link :href="route('admin.organisations')" wire:navigate>
                                    {{ __('Organisations') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('admin.domains')" wire:navigate>
                                    {{ __('Domains') }}
                                </x-dropdown-link>

                                <div class="my-1 border-t border-gray-200 dark:border-gray-600"></div>

                                <x-dropdown-link :href="route('admin.imap-accounts')" wire:navigate>
                                    {{ __('IMAP Accounts') }}
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('admin.geoip')" wire:navigate>
                                    {{ __('GeoIP') }}
                                </x-dropdown-link>

                                <div class="my-1 border-t border-gray-200 dark:border-gray-600"></div>

                                <x-dropdown-link :href="route('admin.alert-rules')" wire:navigate>
                                    {{ __('Alert Rules') }}
                                </x-dropdown-link>
                            @endif
                            @if (auth()->user()->isAdmin())
                                <div class="my-1 border-t border-gray-200 dark:border-gray-600"></div>

                                <x-dropdown-link :href="route('admin.users')" wire:navigate>
                                    {{ __('Users') }}
                                </x-dropdown-link>
                            @endif
                        </x-slot>
                    </x-dropdown>
                @endif

                <button
                    @click="toggleDark()"
                    class="p-2 rounded-md text-gray-400 dark:text-gray-300 hover:text-gray-500 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none transition ease-in-out duration-150"
                    :aria-label="dark ? '{{ __('Switch to light mode') }}' : '{{ __('Switch to dark mode') }}'"
                >
                    <svg x-show="!dark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <svg x-show="dark" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-300 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-100 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden space-x-1">
                <button
                    @click="toggleDark()"
                    class="p-2 rounded-md text-gray-400 dark:text-gray-300 hover:text-gray-500 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none transition ease-in-out duration-150"
                    :aria-label="dark ? '{{ __('Switch to light mode') }}' : '{{ __('Switch to dark mode') }}'"
                >
                    <svg x-show="!dark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <svg x-show="dark" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-300 hover:text-gray-500 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-700 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('reports.index')" :active="request()->routeIs('reports.*')" wire:navigate>
                {{ __('Reports') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('forensic-reports.index')" :active="request()->routeIs('forensic-reports.*')" wire:navigate>
                {{ __('Forensic Reports') }}
            </x-responsive-nav-link>
            @if (auth()->user()->canManage())
                <x-responsive-nav-link :href="route('admin.alert-events')" :active="request()->routeIs('admin.alert-events')" wire:navigate>
                    {{ __('Alerts') }}
                    @if ($openAlertsCount > 0)
                        <span class="ml-1.5 inline-flex items-center justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-medium leading-none text-white">{{ $openAlertsCount > 99 ? '99+' : $openAlertsCount }}</span>
                    @endif
                </x-responsive-nav-link>
            @endif
            @php
                $settingsActive = request()->routeIs(['admin.imap-accounts', 'admin.geoip', 'admin.alert-rules', 'admin.users']);
            @endphp
            @if (auth()->user()->canManage() || auth()->user()->isAdmin())
                <div x-data="{ settingsOpen: {{ $settingsActive ? 'true' : 'false' }} }">
                    <button @click="settingsOpen = ! settingsOpen" type="button" class="flex items-center justify-between w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 dark:hover:border-gray-500 focus:outline-none transition duration-150 ease-in-out">
                        <span class="flex items-center gap-2">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a7.48 7.48 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            {{ __('Settings') }}
                        </span>
                        <svg :class="{ 'rotate-180': settingsOpen }" class="h-4 w-4 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                    <div x-show="settingsOpen">
                        @if (auth()->user()->canManage())
                            <x-responsive-nav-link :href="route('admin.organisations')" :active="request()->routeIs('admin.organisations')" wire:navigate class="ps-6">
                                {{ __('Organisations') }}
                            </x-responsive-nav-link>
                            <x-responsive-nav-link :href="route('admin.domains')" :active="request()->routeIs('admin.domains')" wire:navigate class="ps-6">
                                {{ __('Domains') }}
                            </x-responsive-nav-link>

                            <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>

                            <x-responsive-nav-link :href="route('admin.imap-accounts')" :active="request()->routeIs('admin.imap-accounts')" wire:navigate class="ps-6">
                                {{ __('IMAP Accounts') }}
                            </x-responsive-nav-link>
                            <x-responsive-nav-link :href="route('admin.geoip')" :active="request()->routeIs('admin.geoip')" wire:navigate class="ps-6">
                                {{ __('GeoIP') }}
                            </x-responsive-nav-link>

                            <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>

                            <x-responsive-nav-link :href="route('admin.alert-rules')" :active="request()->routeIs('admin.alert-rules')" wire:navigate class="ps-6">
                                {{ __('Alert Rules') }}
                            </x-responsive-nav-link>
                        @endif
                        @if (auth()->user()->isAdmin())
                            <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>

                            <x-responsive-nav-link :href="route('admin.users')" :active="request()->routeIs('admin.users')" wire:navigate class="ps-6">
                                {{ __('Users') }}
                            </x-responsive-nav-link>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-700">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 dark:text-gray-100" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500 dark:text-gray-400">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
