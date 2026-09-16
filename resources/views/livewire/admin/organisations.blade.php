<?php

use App\Models\Organisation;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    public string $name = '';
    public string $notes = '';

    public function create(): void
    {
        abort_if(auth()->user()->hasOrganisationScope(), 403);

        $this->reset(['editingId', 'name', 'notes']);
        $this->dispatch('open-modal', 'organisation-form');
    }

    public function edit(int $id): void
    {
        abort_unless(auth()->user()->canAccessOrganisation($id), 404);

        $organisation = Organisation::findOrFail($id);
        $this->editingId = $organisation->id;
        $this->name = $organisation->name;
        $this->notes = (string) $organisation->notes;
        $this->dispatch('open-modal', 'organisation-form');
    }

    public function save(): void
    {
        if ($this->editingId === null) {
            abort_if(auth()->user()->hasOrganisationScope(), 403);
        } else {
            abort_unless(auth()->user()->canAccessOrganisation($this->editingId), 404);
        }

        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        Organisation::updateOrCreate(['id' => $this->editingId], $validated);

        $this->dispatch('close-modal', 'organisation-form');
        $this->reset(['editingId', 'name', 'notes']);
    }

    public function delete(int $id): void
    {
        abort_unless(auth()->user()->canAccessOrganisation($id), 404);

        Organisation::findOrFail($id)->delete();
    }

    public function with(): array
    {
        return [
            'organisations' => Organisation::visibleTo(auth()->user())->withCount('domains')->orderBy('name')->paginate(15),
            'organisationScoped' => auth()->user()->hasOrganisationScope(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Organisations') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            @unless ($organisationScoped)
                <div class="flex justify-end mb-4">
                    <x-primary-button wire:click="create">{{ __('New Organisation') }}</x-primary-button>
                </div>
            @endunless

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domains') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Notes') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($organisations as $organisation)
                            <tr wire:key="org-{{ $organisation->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Name') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">{{ $organisation->name }}</td>
                                <td data-label="{{ __('Domains') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $organisation->domains_count }}</td>
                                <td data-label="{{ __('Notes') }}" class="px-6 py-4 text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-start max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:shrink-0">
                                    <span class="max-md:text-right">{{ \Illuminate\Support\Str::limit($organisation->notes, 60) }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3 max-md:px-0 max-md:py-0 max-md:pt-1 max-md:space-x-0 max-md:flex max-md:flex-wrap max-md:gap-3">
                                    <button wire:click="edit({{ $organisation->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $organisation->id }})" wire:confirm="{{ __('Delete this organisation?') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No organisations yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $organisations->links() }}
            </div>
        </div>
    </div>

    <x-modal name="organisation-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit Organisation') : __('New Organisation') }}
            </h2>

            <div class="mt-6">
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input wire:model="name" id="name" type="text" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
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
