<?php

use App\Models\Microsoft365MailAccount;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Everything Microsoft 365 on one page: the mailboxes reports are collected
 * from, the account mail is sent with, and the shared app registration
 * behind "Connect with Microsoft". Only the open tab is loaded.
 */
new #[Layout('layouts.app')] class extends Component
{
    /** Tab key => component rendered in it. */
    public const array TABS = [
        'mailboxes' => 'admin.microsoft365-mail-accounts',
        'sending' => 'admin.microsoft365-send-account',
        'app' => 'admin.microsoft365-app',
    ];

    #[Url]
    public string $tab = 'mailboxes';

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'mailboxes';
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'mailboxes';
    }

    public function with(): array
    {
        return [
            'tabs' => [
                'mailboxes' => __('Mailboxes'),
                'sending' => __('Sending Account'),
                'app' => __('App'),
            ],
            'badges' => ['mailboxes' => Microsoft365MailAccount::failing()->count()],
            'component' => self::TABS[$this->tab],
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Microsoft 365') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-tabs :tabs="$tabs" :active="$tab" :badges="$badges" />

            <livewire:dynamic-component :is="$component" :key="$tab" />
        </div>
    </div>
</div>
