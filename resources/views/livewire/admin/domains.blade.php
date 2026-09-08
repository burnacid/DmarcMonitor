<?php

use App\Models\Domain;
use App\Models\Organisation;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    public string $fqdn = '';
    public ?int $organisation_id = null;
    public bool $is_active = true;
    public string $notes = '';

    public function create(): void
    {
        $this->reset(['editingId', 'fqdn', 'organisation_id', 'notes']);
        $this->is_active = true;
        $this->dispatch('open-modal', 'domain-form');
    }

    public function edit(int $id): void
    {
        $domain = Domain::findOrFail($id);
        $this->editingId = $domain->id;
        $this->fqdn = $domain->fqdn;
        $this->organisation_id = $domain->organisation_id;
        $this->is_active = $domain->is_active;
        $this->notes = (string) $domain->notes;
        $this->dispatch('open-modal', 'domain-form');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'fqdn' => 'required|string|max:255|unique:domains,fqdn,'.$this->editingId,
            'organisation_id' => 'nullable|exists:organisations,id',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        Domain::updateOrCreate(['id' => $this->editingId], $validated);

        $this->dispatch('close-modal', 'domain-form');
        $this->reset(['editingId', 'fqdn', 'organisation_id', 'notes']);
    }

    public function delete(int $id): void
    {
        Domain::findOrFail($id)->delete();
    }

    public function with(): array
    {
        return [
            'domains' => Domain::with('organisation')->orderBy('fqdn')->paginate(15),
            'organisations' => Organisation::orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Domains') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-end mb-4">
                <x-primary-button wire:click="create">{{ __('New Domain') }}</x-primary-button>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domain') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Organisation') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($domains as $domain)
                            <tr wire:key="domain-{{ $domain->id }}">
                                <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">{{ $domain->fqdn }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                    {{ $domain->organisation?->name ?? '—' }}
                                    @unless ($domain->organisation_id)
                                        <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200">{{ __('Unassigned') }}</span>
                                    @endunless
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($domain->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Active') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Inactive') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3">
                                    <button wire:click="edit({{ $domain->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $domain->id }})" wire:confirm="{{ __('Delete this domain?') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No domains yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $domains->links() }}
            </div>
        </div>
    </div>

    <x-modal name="domain-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit Domain') : __('New Domain') }}
            </h2>

            <div class="mt-6">
                <x-input-label for="fqdn" :value="__('Domain (FQDN)')" />
                <x-text-input wire:model="fqdn" id="fqdn" type="text" placeholder="example.com" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('fqdn')" class="mt-2" />
            </div>

            <div class="mt-6">
                <x-input-label for="organisation_id" :value="__('Organisation')" />
                <select wire:model="organisation_id" id="organisation_id" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">{{ __('— Unassigned —') }}</option>
                    @foreach ($organisations as $organisation)
                        <option value="{{ $organisation->id }}">{{ $organisation->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('organisation_id')" class="mt-2" />
            </div>

            <div class="mt-6 flex items-center">
                <input wire:model="is_active" id="is_active" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <label for="is_active" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Active') }}</label>
            </div>

            <div class="mt-6">
                <x-input-label for="notes" :value="__('Notes')" />
                <textarea wire:model="notes" id="notes" rows="3" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="show = false">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
