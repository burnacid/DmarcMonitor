<?php

use App\Models\Microsoft365MailAccount;
use App\Models\Microsoft365SendAccount;
use App\Services\Graph\GraphConnectionTester;
use App\Support\AuditLogger;
use App\Support\Microsoft365App;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $editingId = null;

    public string $label = '';
    public bool $use_shared_app = false;
    public string $tenant_id = '';
    public string $client_id = '';
    public string $client_secret = '';
    public string $mailbox = '';
    public bool $is_active = true;

    public ?string $testResult = null;
    public bool $testResultIsError = false;

    public string $testRecipient = '';

    /**
     * Picks up the result of "Connect with Microsoft": on success, opens the
     * new-account form with the consented tenant already filled in.
     */
    public function mount(): void
    {
        if ($error = session('microsoft365_connect_error')) {
            $this->testResult = __('Connect with Microsoft failed: :error', ['error' => $error]);
            $this->testResultIsError = true;
        }

        if ($tenantId = session('microsoft365_connected_tenant')) {
            $this->create();
            $this->use_shared_app = true;
            $this->tenant_id = $tenantId;
            $this->testResult = __('Tenant connected. Enter the mailbox to send as (a shared mailbox works) to finish.');
            $this->testResultIsError = false;
        }
    }

    public function create(): void
    {
        $this->reset(['editingId', 'label', 'tenant_id', 'client_id', 'client_secret', 'mailbox']);
        $this->use_shared_app = Microsoft365App::isConfigured();
        $this->is_active = true;
        $this->dispatch('open-modal', 'microsoft365-send-account-form');
    }

    public function edit(int $id): void
    {
        $account = Microsoft365SendAccount::findOrFail($id);
        $this->editingId = $account->id;
        $this->label = $account->label;
        $this->use_shared_app = $account->usesSharedApp();
        $this->tenant_id = $account->tenant_id;
        $this->client_id = (string) $account->client_id;
        $this->client_secret = '';
        $this->mailbox = $account->mailbox;
        $this->is_active = $account->is_active;
        $this->dispatch('open-modal', 'microsoft365-send-account-form');
    }

    public function save(): void
    {
        $existing = $this->editingId !== null ? Microsoft365SendAccount::findOrFail($this->editingId) : null;
        $needsNewSecret = $existing === null || $existing->usesSharedApp();

        $validated = $this->validate([
            'label' => 'required|string|max:255',
            'use_shared_app' => [
                'boolean',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value && ! Microsoft365App::isConfigured()) {
                        $fail(__('Connect with Microsoft is not configured on this installation.'));
                    }
                },
            ],
            'tenant_id' => 'required|string|max:255',
            'client_id' => $this->use_shared_app ? 'nullable' : 'required|string|max:255',
            'client_secret' => $this->use_shared_app ? 'nullable' : ($needsNewSecret ? 'required' : 'nullable').'|string',
            'mailbox' => 'required|email|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validated['use_shared_app']) {
            $validated['client_id'] = null;
            $validated['client_secret'] = null;
        } elseif (empty($validated['client_secret'])) {
            unset($validated['client_secret']);
        }

        unset($validated['use_shared_app']);

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
            'sharedAppConfigured' => Microsoft365App::isConfigured(),
            'connectedTenants' => Microsoft365SendAccount::whereNull('client_id')->distinct()->orderBy('tenant_id')->pluck('tenant_id')
                ->merge(Microsoft365MailAccount::whereNull('client_id')->distinct()->pluck('tenant_id'))
                ->unique()->values(),
            'redirectUri' => Microsoft365App::redirectUri(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Microsoft 365 Sending Account') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <x-microsoft365.setup-guide
                :purpose="__('sending mail')"
                permission="Mail.Send"
                :redirect-uri="$redirectUri"
                :shared-app-configured="$sharedAppConfigured"
                example-policy-mailbox="sender@example.com"
            >
                <p class="mt-3">{{ __('Only one active account is used to send mail at a time — activate the one you want in use and set MAIL_MAILER=microsoft365 in the environment configuration.') }}</p>
            </x-microsoft365.setup-guide>

            <div class="flex flex-wrap justify-end gap-3 mb-4">
                @if ($sharedAppConfigured)
                    <a href="{{ route('admin.microsoft365.connect', 'sending') }}" class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">{{ __('Connect with Microsoft') }}</a>
                @endif
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

                <x-microsoft365.credential-fields
                    id-prefix="send_"
                    :editing-id="$editingId"
                    :use-shared-app="$use_shared_app"
                    :shared-app-configured="$sharedAppConfigured"
                    :connected-tenants="$connectedTenants"
                />

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
