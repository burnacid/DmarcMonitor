<?php

use App\Models\DashboardAllowedIp;
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

    public function with(): array
    {
        $currentIp = request()->ip();

        return [
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
                <p>{{ __('A read-only overview of all organisations, meant for wall screens. It can be opened without logging in, but only from the IP addresses and ranges listed below. With no entries the page is unavailable.') }}</p>
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

            <div class="flex justify-end">
                <x-primary-button wire:click="create">{{ __('Add address') }}</x-primary-button>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
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
                                <td colspan="3" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No addresses yet — the public dashboard is unavailable.') }}</td>
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
