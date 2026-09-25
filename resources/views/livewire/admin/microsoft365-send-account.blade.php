<?php

use App\Models\Microsoft365SendAccount;
use App\Services\Graph\GraphConnectionTester;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $editingId = null;

    public string $label = '';
    public string $tenant_id = '';
    public string $client_id = '';
    public string $client_secret = '';
    public string $mailbox = '';
    public bool $is_active = true;

    public ?string $testResult = null;
    public bool $testResultIsError = false;

    public string $testRecipient = '';

    public function create(): void
    {
        $this->reset(['editingId', 'label', 'tenant_id', 'client_id', 'client_secret', 'mailbox']);
        $this->is_active = true;
        $this->dispatch('open-modal', 'microsoft365-send-account-form');
    }

    public function edit(int $id): void
    {
        $account = Microsoft365SendAccount::findOrFail($id);
        $this->editingId = $account->id;
        $this->label = $account->label;
        $this->tenant_id = $account->tenant_id;
        $this->client_id = $account->client_id;
        $this->client_secret = '';
        $this->mailbox = $account->mailbox;
        $this->is_active = $account->is_active;
        $this->dispatch('open-modal', 'microsoft365-send-account-form');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'label' => 'required|string|max:255',
            'tenant_id' => 'required|string|max:255',
            'client_id' => 'required|string|max:255',
            'client_secret' => ($this->editingId ? 'nullable' : 'required').'|string',
            'mailbox' => 'required|email|max:255',
            'is_active' => 'boolean',
        ]);

        if (empty($validated['client_secret'])) {
            unset($validated['client_secret']);
        }

        $wasNew = $this->editingId === null;
        $account = Microsoft365SendAccount::updateOrCreate(['id' => $this->editingId], $validated);

        AuditLogger::record(
            action: $wasNew ? 'microsoft365_send_account.created' : 'microsoft365_send_account.updated',
            description: ($wasNew ? 'Created Microsoft 365 sending account ' : 'Updated Microsoft 365 sending account ').$account->label,
            subject: $account,
            context: $wasNew ? null : AuditLogger::describeChanges($account),
        );

        $this->dispatch('close-modal', 'microsoft365-send-account-form');
        $this->reset(['editingId', 'label', 'tenant_id', 'client_id', 'client_secret', 'mailbox']);
    }

    public function delete(int $id): void
    {
        $account = Microsoft365SendAccount::findOrFail($id);

        AuditLogger::record(
            action: 'microsoft365_send_account.deleted',
            description: 'Deleted Microsoft 365 sending account '.$account->label,
            context: ['label' => $account->label],
        );

        $account->delete();
    }

    public function testConnection(int $id): void
    {
        $account = Microsoft365SendAccount::findOrFail($id);

        $result = app(GraphConnectionTester::class)->testSendAccount($account);

        $account->update(['last_error' => $result === 'ok' ? null : $result]);

        $this->testResultIsError = $result !== 'ok';
        $this->testResult = $result === 'ok'
            ? __('Connection successful.')
            : __('Connection failed: :error', ['error' => $result]);
    }

    public function sendTestEmail(int $id): void
    {
        $account = Microsoft365SendAccount::findOrFail($id);

        $this->validate(['testRecipient' => 'required|email'], attributes: ['testRecipient' => __('recipient')]);

        try {
            Mail::mailer('microsoft365')
                ->raw(__('This is a test email sent from the :label Microsoft 365 sending account.', ['label' => $account->label]), function ($message) {
                    $message->to($this->testRecipient)->subject(__('DMARC Monitor test email'));
                });

            $this->testResultIsError = false;
            $this->testResult = __('Test email sent to :recipient.', ['recipient' => $this->testRecipient]);
        } catch (\Throwable $e) {
            $this->testResultIsError = true;
            $this->testResult = __('Failed to send test email: :error', ['error' => $e->getMessage()]);
        }
    }

    public function with(): array
    {
        return [
            'accounts' => Microsoft365SendAccount::orderBy('label')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Microsoft 365 Sending Account') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <details class="mb-6 bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4 text-sm text-gray-700 dark:text-gray-300">
                <summary class="cursor-pointer font-medium text-gray-900 dark:text-gray-100">{{ __('How to set up an Azure app registration for sending mail') }}</summary>
                <ol class="mt-3 list-decimal list-inside space-y-2">
                    <li>{{ __('In the Microsoft Entra admin center, go to App registrations → New registration. No redirect URI is needed — this is an app-only, non-interactive app.') }}</li>
                    <li>{{ __('Go to API permissions → Add a permission → Microsoft Graph → Application permissions, add Mail.Send, then Grant admin consent.') }}</li>
                    <li>{{ __('Go to Certificates & secrets → New client secret, and copy the value immediately — it is only shown once.') }}</li>
                    <li>{{ __('From the Overview page, copy the Application (client) ID and Directory (tenant) ID into the form below, along with the mailbox address to send as.') }}</li>
                    <li>{{ __('Only one active account is used to send mail at a time — activate the one you want in use and set MAIL_MAILER=microsoft365 in the environment configuration.') }}</li>
                </ol>
                <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                    {{ __('Recommended: scope the app to only this mailbox using an Exchange Online application access policy, so the app-only grant is not tenant-wide mail access:') }}
                    <code class="block mt-1 p-2 rounded bg-gray-100 dark:bg-gray-900 overflow-x-auto">New-ApplicationAccessPolicy -AppId "&lt;client-id&gt;" -PolicyScopeGroupId "sender@example.com" -AccessRight RestrictAccess -Description "DMARC monitor"</code>
                </p>
            </details>

            <div class="flex justify-end mb-4">
                <x-primary-button wire:click="create">{{ __('New Sending Account') }}</x-primary-button>
            </div>

            @if ($testResult)
                <div class="mb-4 rounded-md bg-white dark:bg-gray-800 shadow-sm p-4 text-sm {{ $testResultIsError ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                    {{ $testResult }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Label') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Mailbox') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Last Used') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($accounts as $account)
                            <tr wire:key="m365-send-{{ $account->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Label') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">{{ $account->label }}</td>
                                <td data-label="{{ __('Mailbox') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $account->mailbox }}</td>
                                <td data-label="{{ __('Last Used') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $account->last_used_at?->diffForHumans() ?? '—' }}</td>
                                <td data-label="{{ __('Status') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if ($account->last_error)
                                        <span class="inline-flex items-center rounded-full bg-red-100 dark:bg-red-900 px-2 py-0.5 text-xs font-medium text-red-800 dark:text-red-200" title="{{ $account->last_error }}">{{ __('Error') }}</span>
                                    @elseif ($account->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Active') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Inactive') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3 max-md:px-0 max-md:py-0 max-md:pt-1 max-md:space-x-0 max-md:flex max-md:flex-wrap max-md:gap-3">
                                    <button wire:click="testConnection({{ $account->id }})" wire:loading.attr="disabled" class="text-blue-600 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300">
                                        <span wire:loading.remove wire:target="testConnection({{ $account->id }})">{{ __('Test') }}</span>
                                        <span wire:loading wire:target="testConnection({{ $account->id }})">{{ __('Testing…') }}</span>
                                    </button>
                                    <button wire:click="edit({{ $account->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $account->id }})" wire:confirm="{{ __('Delete this sending account?') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No Microsoft 365 sending accounts yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($accounts->isNotEmpty())
                <div class="mt-4 bg-white dark:bg-gray-800 shadow-sm rounded-lg p-4">
                    <label for="testRecipient" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Send a test email using the active account') }}</label>
                    <div class="mt-2 flex flex-wrap gap-3">
                        <x-text-input wire:model="testRecipient" id="testRecipient" type="email" placeholder="you@example.com" class="flex-1 min-w-[16rem]" />
                        @php($activeAccount = $accounts->firstWhere('is_active', true))
                        @if ($activeAccount)
                            <x-secondary-button type="button" wire:click="sendTestEmail({{ $activeAccount->id }})">
                                {{ __('Send test email') }}
                            </x-secondary-button>
                        @else
                            <x-secondary-button type="button" disabled>
                                {{ __('Send test email') }}
                            </x-secondary-button>
                        @endif
                    </div>
                    <x-input-error :messages="$errors->get('testRecipient')" class="mt-2" />
                    @if (! $activeAccount)
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">{{ __('No account is marked active — activate one above to send a test email.') }}</p>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <x-modal name="microsoft365-send-account-form" max-width="2xl" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit Sending Account') : __('New Sending Account') }}
            </h2>

            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="send_label" :value="__('Label')" />
                    <x-text-input wire:model="label" id="send_label" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('label')" class="mt-2" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="send_mailbox" :value="__('Mailbox address (send as)')" />
                    <x-text-input wire:model="mailbox" id="send_mailbox" type="email" placeholder="notifications@example.com" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('mailbox')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="send_tenant_id" :value="__('Directory (tenant) ID')" />
                    <x-text-input wire:model="tenant_id" id="send_tenant_id" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('tenant_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="send_client_id" :value="__('Application (client) ID')" />
                    <x-text-input wire:model="client_id" id="send_client_id" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="send_client_secret" :value="__('Client secret')" />
                    <x-text-input wire:model="client_secret" id="send_client_secret" type="password" :placeholder="$editingId ? __('Leave blank to keep current') : ''" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('client_secret')" class="mt-2" />
                </div>

                <div class="flex items-center">
                    <input wire:model="is_active" id="send_is_active" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    <label for="send_is_active" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Active') }}</label>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="show = false">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
