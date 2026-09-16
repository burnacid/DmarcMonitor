<?php

use App\Models\AlertRule;
use App\Models\Organisation;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public const array TYPES = ['pass_rate_drop', 'spf_fail_spike', 'dkim_fail_spike', 'new_source_detected', 'new_domain_discovered'];

    public const array NO_THRESHOLD_TYPES = ['new_source_detected', 'new_domain_discovered'];

    public ?int $editingId = null;

    public ?int $organisation_id = null;
    public string $type = 'pass_rate_drop';
    public ?float $threshold_percent = null;
    public string $lookback_window = '24h';
    /** @var array<int, string> */
    public array $channels = ['email'];
    public string $webhook_url = '';
    public string $notify_emails = '';
    public bool $is_active = true;

    public function create(): void
    {
        $this->reset(['editingId', 'organisation_id', 'webhook_url', 'notify_emails']);
        $this->type = 'pass_rate_drop';
        $this->threshold_percent = 95.0;
        $this->lookback_window = '24h';
        $this->channels = ['email'];
        $this->is_active = true;

        $scopedIds = auth()->user()->scopedOrganisationIds();
        if ($scopedIds !== null && count($scopedIds) === 1) {
            $this->organisation_id = $scopedIds[0];
        }

        $this->dispatch('open-modal', 'alert-rule-form');
    }

    public function edit(int $id): void
    {
        $rule = AlertRule::visibleTo(auth()->user())->findOrFail($id);
        $this->editingId = $rule->id;
        $this->organisation_id = $rule->organisation_id;
        $this->type = $rule->type;
        $this->threshold_percent = $rule->threshold_percent !== null ? (float) $rule->threshold_percent : null;
        $this->lookback_window = $rule->lookback_window;
        $this->channels = $rule->channels ?? [];
        $this->webhook_url = (string) $rule->webhook_url;
        $this->notify_emails = implode(', ', $rule->notify_emails ?? []);
        $this->is_active = $rule->is_active;
        $this->dispatch('open-modal', 'alert-rule-form');
    }

    public function save(): void
    {
        $user = auth()->user();
        $scopedToOrg = $user->hasOrganisationScope();

        if ($this->editingId !== null) {
            AlertRule::visibleTo($user)->findOrFail($this->editingId);
        }

        $validated = $this->validate([
            'organisation_id' => [
                ($scopedToOrg && $this->type !== 'new_domain_discovered') ? 'required' : 'nullable',
                'exists:organisations,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if ($value !== null && ! Organisation::visibleTo($user)->whereKey($value)->exists()) {
                        $fail(__('You do not have access to that organisation.'));
                    }
                },
            ],
            'type' => [
                'required',
                'in:'.implode(',', self::TYPES),
                function (string $attribute, mixed $value, \Closure $fail) use ($scopedToOrg): void {
                    if ($value === 'new_domain_discovered' && $scopedToOrg) {
                        $fail(__('You do not have access to create this rule type.'));
                    }
                },
            ],
            'threshold_percent' => 'nullable|numeric|min:0|max:100',
            'lookback_window' => ['required', 'regex:/^\d+[hd]$/'],
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:email,webhook,in_app',
            'webhook_url' => 'nullable|url',
            'is_active' => 'boolean',
        ]);

        if (! in_array($validated['type'], self::NO_THRESHOLD_TYPES, true) && $validated['threshold_percent'] === null) {
            $this->addError('threshold_percent', __('A threshold is required for this rule type.'));

            return;
        }

        if ($validated['type'] === 'new_domain_discovered') {
            $validated['organisation_id'] = null;
        }

        if (in_array('webhook', $validated['channels'], true) && blank($validated['webhook_url'])) {
            $this->addError('webhook_url', __('A webhook URL is required when the webhook channel is enabled.'));

            return;
        }

        $emails = collect(preg_split('/[,\n]+/', $this->notify_emails))
            ->map(fn ($email) => trim($email))
            ->filter()
            ->values();

        if (in_array('email', $validated['channels'], true) && $emails->isEmpty()) {
            $this->addError('notify_emails', __('At least one email address is required when the email channel is enabled.'));

            return;
        }

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError('notify_emails', __('":email" is not a valid email address.', ['email' => $email]));

                return;
            }
        }

        $validated['notify_emails'] = $emails->all();
        $validated['webhook_url'] = $validated['webhook_url'] ?: null;

        AlertRule::updateOrCreate(['id' => $this->editingId], $validated);

        $this->dispatch('close-modal', 'alert-rule-form');
        $this->reset(['editingId', 'organisation_id', 'webhook_url', 'notify_emails']);
    }

    public function delete(int $id): void
    {
        AlertRule::visibleTo(auth()->user())->findOrFail($id)->delete();
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'rules' => AlertRule::visibleTo($user)->with('organisation')->orderBy('type')->paginate(15),
            'organisations' => Organisation::visibleTo($user)->orderBy('name')->get(),
            'organisationScoped' => $user->hasOrganisationScope(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Alert Rules') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-end mb-4">
                <x-primary-button wire:click="create">{{ __('New Alert Rule') }}</x-primary-button>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Type') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Organisation') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Threshold') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Window') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Channels') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($rules as $rule)
                            <tr wire:key="rule-{{ $rule->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Type') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">{{ str_replace('_', ' ', $rule->type) }}</td>
                                <td data-label="{{ __('Organisation') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $rule->organisation?->name ?? __('All organisations') }}</td>
                                <td data-label="{{ __('Threshold') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $rule->threshold_percent !== null ? $rule->threshold_percent.'%' : '—' }}</td>
                                <td data-label="{{ __('Window') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $rule->lookback_window }}</td>
                                <td data-label="{{ __('Channels') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ implode(', ', array_map(fn ($channel) => str_replace('_', ' ', $channel), $rule->channels ?? [])) }}</td>
                                <td data-label="{{ __('Status') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if ($rule->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Active') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Inactive') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3 max-md:px-0 max-md:py-0 max-md:pt-1 max-md:space-x-0 max-md:flex max-md:flex-wrap max-md:gap-3">
                                    <button wire:click="edit({{ $rule->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $rule->id }})" wire:confirm="{{ __('Delete this alert rule?') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No alert rules yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $rules->links() }}
            </div>
        </div>
    </div>

    <x-modal name="alert-rule-form" max-width="2xl" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit Alert Rule') : __('New Alert Rule') }}
            </h2>

            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="type" :value="__('Type')" />
                    <select wire:model.live="type" id="type" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        <option value="pass_rate_drop">{{ __('DMARC pass rate drop') }}</option>
                        <option value="spf_fail_spike">{{ __('SPF fail spike') }}</option>
                        <option value="dkim_fail_spike">{{ __('DKIM fail spike') }}</option>
                        <option value="new_source_detected">{{ __('New sending source detected') }}</option>
                        @unless ($organisationScoped)
                            <option value="new_domain_discovered">{{ __('New domain discovered') }}</option>
                        @endunless
                    </select>
                    <x-input-error :messages="$errors->get('type')" class="mt-2" />
                </div>

                @unless ($type === 'new_domain_discovered')
                    <div>
                        <x-input-label for="organisation_id" :value="__('Organisation')" />
                        <select wire:model="organisation_id" id="organisation_id" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            @if ($organisationScoped)
                                <option value="" disabled>{{ __('— Select organisation —') }}</option>
                            @else
                                <option value="">{{ __('— All organisations —') }}</option>
                            @endif
                            @foreach ($organisations as $organisation)
                                <option value="{{ $organisation->id }}">{{ $organisation->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('organisation_id')" class="mt-2" />
                    </div>
                @else
                    <p class="text-sm text-gray-400 dark:text-gray-500 self-end pb-2">{{ __('Applies across all domains — there\'s no existing organisation to scope it to.') }}</p>
                @endunless

                @unless (in_array($type, ['new_source_detected', 'new_domain_discovered']))
                    <div>
                        <x-input-label for="threshold_percent" :value="__('Threshold %')" />
                        <x-text-input wire:model="threshold_percent" id="threshold_percent" type="number" step="0.1" min="0" max="100" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('threshold_percent')" class="mt-2" />
                    </div>
                @endunless

                <div>
                    <x-input-label for="lookback_window" :value="__('Lookback window')" />
                    <x-text-input wire:model="lookback_window" id="lookback_window" type="text" placeholder="24h" class="mt-1 block w-full" />
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('A number followed by h (hours) or d (days), e.g. 24h or 7d.') }}</p>
                    <x-input-error :messages="$errors->get('lookback_window')" class="mt-2" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label :value="__('Channels')" />
                    <div class="mt-1 flex items-center gap-6">
                        <div class="flex items-center">
                            <input wire:model.live="channels" value="email" id="channel_email" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <label for="channel_email" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Email') }}</label>
                        </div>
                        <div class="flex items-center">
                            <input wire:model.live="channels" value="webhook" id="channel_webhook" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <label for="channel_webhook" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Webhook') }}</label>
                        </div>
                        <div class="flex items-center">
                            <input wire:model.live="channels" value="in_app" id="channel_in_app" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <label for="channel_in_app" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('In-app only') }}</label>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('In-app alerts always appear on the Alerts page and the nav badge — enable it alone for alerts that shouldn\'t send email or a webhook.') }}</p>
                    <x-input-error :messages="$errors->get('channels')" class="mt-2" />
                </div>

                @if (in_array('email', $channels))
                    <div class="sm:col-span-2">
                        <x-input-label for="notify_emails" :value="__('Notify emails')" />
                        <textarea wire:model="notify_emails" id="notify_emails" rows="2" placeholder="ops@example.com, security@example.com" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Comma or newline separated.') }}</p>
                        <x-input-error :messages="$errors->get('notify_emails')" class="mt-2" />
                    </div>
                @endif

                @if (in_array('webhook', $channels))
                    <div class="sm:col-span-2">
                        <x-input-label for="webhook_url" :value="__('Webhook URL')" />
                        <x-text-input wire:model="webhook_url" id="webhook_url" type="text" placeholder="https://example.com/webhooks/dmarc-alerts" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('webhook_url')" class="mt-2" />
                    </div>
                @endif

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
