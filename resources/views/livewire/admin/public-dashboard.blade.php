<?php

use App\Models\DashboardAllowedIp;
use App\Models\PublicDashboardSetting;
use App\Rules\IpOrCidr;
use App\Support\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $editingId = null;

    public string $address = '';
    public string $description = '';

    public function create(?string $address = null): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'address', 'description']);
        $this->address = (string) $address;
        $this->dispatch('open-modal', 'allowed-ip-form');
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $allowedIp = DashboardAllowedIp::findOrFail($id);
        $this->editingId = $allowedIp->id;
        $this->address = $allowedIp->address;
        $this->description = (string) $allowedIp->description;
        $this->dispatch('open-modal', 'allowed-ip-form');
    }

    public function save(): void
    {
        $this->address = trim($this->address);

        $validated = $this->validate([
            'address' => ['required', 'string', 'max:64', new IpOrCidr, Rule::unique('dashboard_allowed_ips', 'address')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $wasNew = $this->editingId === null;
        $allowedIp = DashboardAllowedIp::updateOrCreate(['id' => $this->editingId], [
            'address' => $validated['address'],
            'description' => $validated['description'] ?: null,
        ]);

        AuditLogger::record(
            action: $wasNew ? 'dashboard_ip.created' : 'dashboard_ip.updated',
            description: ($wasNew ? 'Allowed ' : 'Updated allowed ').$allowedIp->address.' for the public dashboard',
            subject: $allowedIp,
            context: $wasNew ? null : AuditLogger::describeChanges($allowedIp),
        );

        $this->dispatch('close-modal', 'allowed-ip-form');
        $this->reset(['editingId', 'address', 'description']);
    }

    public function delete(int $id): void
    {
        $allowedIp = DashboardAllowedIp::findOrFail($id);

        AuditLogger::record(
            action: 'dashboard_ip.deleted',
            description: 'Removed '.$allowedIp->address.' from the public dashboard',
            context: ['address' => $allowedIp->address],
        );

        $allowedIp->delete();
    }

    public function toggleEnabled(): void
    {
        $setting = PublicDashboardSetting::setEnabled(! PublicDashboardSetting::isEnabled());

        AuditLogger::record(
            action: $setting->is_enabled ? 'public_dashboard.enabled' : 'public_dashboard.disabled',
            description: ($setting->is_enabled ? 'Enabled' : 'Disabled').' the public dashboard',
            subject: $setting,
        );
    }

    public function toggleAutoScroll(): void
    {
        $setting = PublicDashboardSetting::setAutoScroll(! PublicDashboardSetting::autoScrolls());

        AuditLogger::record(
            action: $setting->auto_scroll ? 'public_dashboard.auto_scroll_enabled' : 'public_dashboard.auto_scroll_disabled',
            description: ($setting->auto_scroll ? 'Enabled' : 'Disabled').' auto-scrolling on the public dashboard',
            subject: $setting,
        );
    }

    public function setDefaultDays(int $days): void
    {
        if (! in_array($days, PublicDashboardSetting::DAY_RANGES, true)) {
            return;
        }

        $setting = PublicDashboardSetting::setDefaultDays($days);

        AuditLogger::record(
            action: 'public_dashboard.default_days_changed',
            description: 'Set the default range of the public dashboard to '.$days.' days',
            subject: $setting,
        );
    }

    public function with(): array
    {
        $currentIp = request()->ip();

        return [
            'isEnabled' => PublicDashboardSetting::isEnabled(),
            'autoScroll' => PublicDashboardSetting::autoScrolls(),
            'defaultDays' => PublicDashboardSetting::defaultDays(),
            'trustsAnyProxy' => trim((string) config('app.trusted_proxies')) === '*',
            'allowedIps' => DashboardAllowedIp::orderBy('address')->get(),
            'currentIp' => $currentIp,
            'currentIpAllowed' => DashboardAllowedIp::allows($currentIp),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Public Dashboard') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                <p>{{ __('A read-only overview of all organisations, meant for wall screens. While enabled, logged-in users can always open it; without logging in it can only be opened from the IP addresses and ranges listed below. While disabled, nobody can open it.') }}</p>
                <p class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ __('Status') }}:</span>
                    @if ($isEnabled)
                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Enabled') }}</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-800 dark:text-gray-200">{{ __('Disabled') }}</span>
                    @endif
                    <button type="button" wire:click="toggleEnabled" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ $isEnabled ? __('Disable') : __('Enable') }}</button>
                </p>
                <p class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ __('Auto-scroll organisations') }}:</span>
                    @if ($autoScroll)
                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('On') }}</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-800 dark:text-gray-200">{{ __('Off') }}</span>
                    @endif
                    <button type="button" wire:click="toggleAutoScroll" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ $autoScroll ? __('Turn off') : __('Turn on') }}</button>
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('Slowly scrolls the organisations panel when it does not fit on the screen.') }}</span>
                </p>
                <p class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ __('Default range') }}:</span>
                    <span class="inline-flex rounded-md shadow-sm" role="group">
                        @foreach (PublicDashboardSetting::DAY_RANGES as $value)
                            <button type="button" wire:click="setDefaultDays({{ $value }})" @class([
                                'px-3 py-1 text-xs font-medium border first:rounded-l-md last:rounded-r-md -ml-px first:ml-0',
                                'bg-indigo-600 text-white border-indigo-600' => $defaultDays === $value,
                                'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' => $defaultDays !== $value,
                            ])>{{ $value }}d</button>
                        @endforeach
                    </span>
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('Shown when the wallboard address does not pick a range.') }}</span>
                </p>
                <p>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ __('URL') }}:</span>
                    <a href="{{ route('public.overview') }}" target="_blank" rel="noopener" class="font-mono text-indigo-600 dark:text-indigo-400 hover:underline break-all">{{ route('public.overview') }}</a>
                </p>
                <p class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ __('Your IP') }}:</span>
                    <span class="font-mono">{{ $currentIp }}</span>
                    @if ($currentIpAllowed)
                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Allowed') }}</span>
                    @elseif ($currentIp)
                        <button type="button" wire:click="create('{{ $currentIp }}')" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Add my IP') }}</button>
                    @endif
                </p>
            </div>

            @if ($trustsAnyProxy && $allowedIps->isNotEmpty())
                <div class="rounded-md bg-amber-50 dark:bg-amber-950 p-4 text-sm text-amber-800 dark:text-amber-200">
                    {{ __('TRUSTED_PROXIES is set to "*", so the X-Forwarded-For header of every request is believed. Anyone who can reach the app without going through your reverse proxy can pretend to come from an allowed address. Set TRUSTED_PROXIES to the address of your proxy (or the Docker network range) instead.') }}
                </div>
            @endif

            <div class="flex justify-end">
                <x-primary-button wire:click="create">{{ __('Add address') }}</x-primary-button>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-x-auto shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Address / range') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Description') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($allowedIps as $allowedIp)
                            <tr wire:key="allowed-ip-{{ $allowedIp->id }}">
                                <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-gray-900 dark:text-gray-100">{{ $allowedIp->address }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $allowedIp->description }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3">
                                    <button wire:click="edit({{ $allowedIp->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $allowedIp->id }})" wire:confirm="{{ __('Remove this address?') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No addresses yet — only logged-in users can open the wallboard.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-modal name="allowed-ip-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit Address') : __('Add Address') }}
            </h2>

            <div class="mt-6">
                <x-input-label for="address" :value="__('IP address or CIDR range')" />
                <x-text-input wire:model="address" id="address" type="text" placeholder="192.0.2.10 / 192.0.2.0/24" class="mt-1 block w-full font-mono" />
                <x-input-error :messages="$errors->get('address')" class="mt-2" />
            </div>

            <div class="mt-6">
                <x-input-label for="description" :value="__('Description')" />
                <x-text-input wire:model="description" id="description" type="text" :placeholder="__('e.g. Office wall screen')" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="show = false">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
