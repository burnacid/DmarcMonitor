<?php

use App\Models\Domain;
use App\Models\ImapAccount;
use App\Services\Imap\ImapConnectionTester;
use App\Services\Imap\ImapIngestionService;
use App\Support\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    public string $label = '';
    public string $host = '';
    public int $port = 993;
    public string $encryption = 'ssl';
    public string $username = '';
    public string $password = '';
    public string $folder_inbox = 'INBOX';
    public string $folder_processed = '';
    public string $folder_failed = '';
    public bool $mark_as_read = true;
    public bool $include_read_messages = false;
    public bool $delete_after_processing = false;
    public bool $is_active = true;
    /** @var array<int> */
    public array $domain_ids = [];

    public ?string $testResult = null;
    public bool $testResultIsError = false;
    public ?int $testingId = null;

    public function create(): void
    {
        $this->reset([
            'editingId', 'label', 'host', 'username', 'password',
            'folder_processed', 'folder_failed', 'domain_ids',
        ]);
        $this->port = 993;
        $this->encryption = 'ssl';
        $this->folder_inbox = 'INBOX';
        $this->mark_as_read = true;
        $this->include_read_messages = false;
        $this->delete_after_processing = false;
        $this->is_active = true;
        $this->dispatch('open-modal', 'imap-account-form');
    }

    public function edit(int $id): void
    {
        $account = ImapAccount::visibleTo(auth()->user())->findOrFail($id);
        $this->editingId = $account->id;
        $this->label = $account->label;
        $this->host = $account->host;
        $this->port = $account->port;
        $this->encryption = $account->encryption;
        $this->username = $account->username;
        $this->password = '';
        $this->folder_inbox = $account->folder_inbox;
        $this->folder_processed = (string) $account->folder_processed;
        $this->folder_failed = (string) $account->folder_failed;
        $this->mark_as_read = $account->mark_as_read;
        $this->include_read_messages = $account->include_read_messages;
        $this->delete_after_processing = $account->delete_after_processing;
        $this->is_active = $account->is_active;
        $this->domain_ids = $account->domains()->pluck('domains.id')->all();
        $this->dispatch('open-modal', 'imap-account-form');
    }

    public function save(): void
    {
        $user = auth()->user();

        if ($this->editingId !== null) {
            ImapAccount::visibleTo($user)->findOrFail($this->editingId);
        }

        $validated = $this->validate([
            'label' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'encryption' => 'required|in:ssl,tls,none',
            'username' => 'required|string|max:255',
            'password' => ($this->editingId ? 'nullable' : 'required').'|string',
            'folder_inbox' => 'required|string|max:255',
            'folder_processed' => 'nullable|string|max:255',
            'folder_failed' => 'nullable|string|max:255',
            'mark_as_read' => 'boolean',
            'include_read_messages' => 'boolean',
            'delete_after_processing' => 'boolean',
            'is_active' => 'boolean',
            'domain_ids' => 'array',
            'domain_ids.*' => [
                'exists:domains,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (! Domain::visibleTo($user)->whereKey($value)->exists()) {
                        $fail(__('You do not have access to that domain.'));
                    }
                },
            ],
        ]);

        $domainIds = $validated['domain_ids'] ?? [];
        unset($validated['domain_ids']);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $wasNew = $this->editingId === null;
        $account = ImapAccount::updateOrCreate(['id' => $this->editingId], $validated);
        $account->domains()->sync($domainIds);

        AuditLogger::record(
            action: $wasNew ? 'imap_account.created' : 'imap_account.updated',
            description: ($wasNew ? 'Created IMAP account ' : 'Updated IMAP account ').$account->label,
            subject: $account,
            context: $wasNew ? null : AuditLogger::describeChanges($account),
        );

        $this->dispatch('close-modal', 'imap-account-form');
        $this->reset([
            'editingId', 'label', 'host', 'username', 'password',
            'folder_processed', 'folder_failed', 'domain_ids',
        ]);
    }

    public function delete(int $id): void
    {
        $account = ImapAccount::visibleTo(auth()->user())->findOrFail($id);

        AuditLogger::record(
            action: 'imap_account.deleted',
            description: 'Deleted IMAP account '.$account->label,
            context: ['label' => $account->label],
        );

        $account->delete();
    }

    public function testConnection(int $id): void
    {
        $account = ImapAccount::visibleTo(auth()->user())->findOrFail($id);
        $this->testingId = $id;

        $result = app(ImapConnectionTester::class)->test($account);

        $account->update([
            'last_error' => $result === 'ok' ? null : $result,
        ]);

        $this->testResultIsError = $result !== 'ok';
        $this->testResult = $result === 'ok'
            ? __('Connection successful.')
            : __('Connection failed: :error', ['error' => $result]);

        $this->testingId = null;
    }

    public function fetchNow(int $id): void
    {
        $account = ImapAccount::visibleTo(auth()->user())->findOrFail($id);

        $stats = app(ImapIngestionService::class)->pollAccount($account);

        if ($stats['fetched'] > 0 || $stats['failed'] > 0) {
            AuditLogger::record(
                action: 'ingestion.completed',
                description: "IMAP poll [{$account->label}]: {$stats['parsed']} parsed, {$stats['failed']} failed, out of {$stats['fetched']} fetched",
                subject: $account,
                context: $stats,
            );
        }

        $this->testResultIsError = $stats['failed'] > 0;
        $this->testResult = __(':parsed report(s) parsed, :failed failed, out of :fetched message(s) fetched.', $stats);

        if ($stats['more_remaining']) {
            $this->testResult .= ' '.__('This mailbox has more messages than fit in one run — click Fetch now again (or wait for the next scheduled poll) to continue.');
        }
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'accounts' => ImapAccount::visibleTo($user)->withCount('domains')->orderBy('label')->paginate(15),
            'domains' => Domain::visibleTo($user)->orderBy('fqdn')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('IMAP Accounts') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-end mb-4">
                <x-primary-button wire:click="create">{{ __('New IMAP Account') }}</x-primary-button>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Host') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domains') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Last Polled') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($accounts as $account)
                            <tr wire:key="imap-{{ $account->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Label') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">{{ $account->label }}</td>
                                <td data-label="{{ __('Host') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $account->host }}:{{ $account->port }}</td>
                                <td data-label="{{ __('Domains') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $account->domains_count }}</td>
                                <td data-label="{{ __('Last Polled') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $account->last_polled_at?->diffForHumans() ?? '—' }}</td>
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
                                    <button wire:click="fetchNow({{ $account->id }})" wire:loading.attr="disabled" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-900 dark:hover:text-emerald-300">
                                        <span wire:loading.remove wire:target="fetchNow({{ $account->id }})">{{ __('Fetch now') }}</span>
                                        <span wire:loading wire:target="fetchNow({{ $account->id }})">{{ __('Fetching…') }}</span>
                                    </button>
                                    <button wire:click="testConnection({{ $account->id }})" wire:loading.attr="disabled" class="text-blue-600 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300">
                                        <span wire:loading.remove wire:target="testConnection({{ $account->id }})">{{ __('Test') }}</span>
                                        <span wire:loading wire:target="testConnection({{ $account->id }})">{{ __('Testing…') }}</span>
                                    </button>
                                    <button wire:click="edit({{ $account->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $account->id }})" wire:confirm="{{ __('Delete this IMAP account?') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No IMAP accounts yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $accounts->links() }}
            </div>
        </div>
    </div>

    <x-modal name="imap-account-form" max-width="2xl" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit IMAP Account') : __('New IMAP Account') }}
            </h2>

            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="label" :value="__('Label')" />
                    <x-text-input wire:model="label" id="label" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('label')" class="mt-2" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="host" :value="__('Host')" />
                    <x-text-input wire:model="host" id="host" type="text" placeholder="imap.example.com" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('host')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="port" :value="__('Port')" />
                    <x-text-input wire:model="port" id="port" type="number" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('port')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="encryption" :value="__('Encryption')" />
                    <select wire:model="encryption" id="encryption" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="ssl">SSL</option>
                        <option value="tls">TLS</option>
                        <option value="none">None</option>
                    </select>
                    <x-input-error :messages="$errors->get('encryption')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="username" :value="__('Username')" />
                    <x-text-input wire:model="username" id="username" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('username')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="password" :value="__('Password')" />
                    <x-text-input wire:model="password" id="password" type="password" :placeholder="$editingId ? __('Leave blank to keep current') : ''" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="folder_inbox" :value="__('Inbox Folder')" />
                    <x-text-input wire:model="folder_inbox" id="folder_inbox" type="text" class="mt-1 block w-full" />
                </div>

                <div>
                    <x-input-label for="folder_processed" :value="__('Processed Folder (optional)')" />
                    <x-text-input wire:model="folder_processed" id="folder_processed" type="text" class="mt-1 block w-full" />
                </div>

                <div>
                    <x-input-label for="folder_failed" :value="__('Failed Folder (optional)')" />
                    <x-text-input wire:model="folder_failed" id="folder_failed" type="text" class="mt-1 block w-full" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="domain_ids" :value="__('Domains served by this mailbox')" />
                    <select wire:model="domain_ids" id="domain_ids" multiple class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" size="4">
                        @foreach ($domains as $domain)
                            <option value="{{ $domain->id }}">{{ $domain->fqdn }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center">
                    <input wire:model="mark_as_read" id="mark_as_read" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    <label for="mark_as_read" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Mark processed messages as read') }}</label>
                </div>

                <div class="sm:col-span-2">
                    <div class="flex items-center">
                        <input wire:model="include_read_messages" id="include_read_messages" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                        <label for="include_read_messages" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Also fetch already-read messages') }}</label>
                    </div>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('By default only unread messages are polled. Enable this to also pick up messages already marked as read (useful for a mailbox shared with other tools, or a first import).') }}</p>
                </div>

                <div class="flex items-center">
                    <input wire:model="delete_after_processing" id="delete_after_processing" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    <label for="delete_after_processing" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Delete after processing') }}</label>
                </div>

                <div class="flex items-center">
                    <input wire:model="is_active" id="is_active" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    <label for="is_active" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Active') }}</label>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="show = false">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
