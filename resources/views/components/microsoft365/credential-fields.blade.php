@props([
    'idPrefix' => '',
    'editingId' => null,
    'useSharedApp' => false,
    'sharedAppConfigured' => false,
    'connectedTenants' => collect(),
])

@if ($sharedAppConfigured || $useSharedApp)
    <fieldset class="sm:col-span-2">
        <legend class="block font-medium text-sm text-gray-700 dark:text-gray-300">{{ __('Authentication') }}</legend>
        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
            <label class="inline-flex items-center">
                <input wire:model.live="use_shared_app" type="radio" value="1" id="{{ $idPrefix }}use_shared_app_yes" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Connect with Microsoft') }}</span>
            </label>
            <label class="inline-flex items-center">
                <input wire:model.live="use_shared_app" type="radio" value="0" id="{{ $idPrefix }}use_shared_app_no" class="border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Own app registration') }}</span>
            </label>
        </div>
        <x-input-error :messages="$errors->get('use_shared_app')" class="mt-2" />
    </fieldset>
@endif

@if ($useSharedApp)
    <div class="sm:col-span-2">
        <x-input-label :for="$idPrefix.'tenant_id'" :value="__('Directory (tenant) ID')" />
        <x-text-input wire:model="tenant_id" :id="$idPrefix.'tenant_id'" type="text" :list="$idPrefix.'connected_tenants'" class="mt-1 block w-full" />
        <datalist id="{{ $idPrefix }}connected_tenants">
            @foreach ($connectedTenants as $tenant)
                <option value="{{ $tenant }}"></option>
            @endforeach
        </datalist>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Filled in by "Connect with Microsoft". For another mailbox in a tenant that is already connected, just enter (or pick) its tenant ID.') }}</p>
        <x-input-error :messages="$errors->get('tenant_id')" class="mt-2" />
    </div>
@else
    <div>
        <x-input-label :for="$idPrefix.'tenant_id'" :value="__('Directory (tenant) ID')" />
        <x-text-input wire:model="tenant_id" :id="$idPrefix.'tenant_id'" type="text" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('tenant_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label :for="$idPrefix.'client_id'" :value="__('Application (client) ID')" />
        <x-text-input wire:model="client_id" :id="$idPrefix.'client_id'" type="text" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label :for="$idPrefix.'client_secret'" :value="__('Client secret')" />
        <x-text-input wire:model="client_secret" :id="$idPrefix.'client_secret'" type="password" :placeholder="$editingId ? __('Leave blank to keep current') : ''" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('client_secret')" class="mt-2" />
    </div>
@endif
