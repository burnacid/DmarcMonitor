<?php

use App\Livewire\Actions\Logout;
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
                        <x-nav-link :href="route('admin.organisations')" :active="request()->routeIs('admin.organisations')" wire:navigate>
                            {{ __('Organisations') }}
                        </x-nav-link>
                        <x-nav-link :href="route('admin.domains')" :active="request()->routeIs('admin.domains')" wire:navigate>
                            {{ __('Domains') }}
                        </x-nav-link>
                        <x-nav-link :href="route('admin.imap-accounts')" :active="request()->routeIs('admin.imap-accounts')" wire:navigate>
                            {{ __('IMAP Accounts') }}
                        </x-nav-link>
                        <x-nav-link :href="route('admin.geoip')" :active="request()->routeIs('admin.geoip')" wire:navigate>
                            {{ __('GeoIP') }}
                        </x-nav-link>
                        <x-nav-link :href="route('admin.alert-rules')" :active="request()->routeIs('admin.alert-rules')" wire:navigate>
                            {{ __('Alert Rules') }}
                        </x-nav-link>
                        <x-nav-link :href="route('admin.alert-events')" :active="request()->routeIs('admin.alert-events')" wire:navigate>
                            {{ __('Alerts') }}
                        </x-nav-link>
                    @endif
                    @if (auth()->user()->isAdmin())
                        <x-nav-link :href="route('admin.users')" :active="request()->routeIs('admin.users')" wire:navigate>
                            {{ __('Users') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 space-x-2">
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
                <x-responsive-nav-link :href="route('admin.organisations')" :active="request()->routeIs('admin.organisations')" wire:navigate>
                    {{ __('Organisations') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.domains')" :active="request()->routeIs('admin.domains')" wire:navigate>
                    {{ __('Domains') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.imap-accounts')" :active="request()->routeIs('admin.imap-accounts')" wire:navigate>
                    {{ __('IMAP Accounts') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.geoip')" :active="request()->routeIs('admin.geoip')" wire:navigate>
                    {{ __('GeoIP') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.alert-rules')" :active="request()->routeIs('admin.alert-rules')" wire:navigate>
                    {{ __('Alert Rules') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('admin.alert-events')" :active="request()->routeIs('admin.alert-events')" wire:navigate>
                    {{ __('Alerts') }}
                </x-responsive-nav-link>
            @endif
            @if (auth()->user()->isAdmin())
                <x-responsive-nav-link :href="route('admin.users')" :active="request()->routeIs('admin.users')" wire:navigate>
                    {{ __('Users') }}
                </x-responsive-nav-link>
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
