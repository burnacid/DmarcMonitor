<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Passkey;
use Livewire\Volt\Component;

new class extends Component
{
    public function delete(int $passkeyId): void
    {
        $passkey = Passkey::findOrFail($passkeyId);

        abort_unless($passkey->user_id === Auth::id(), 403);

        $passkey->delete();
    }

    public function with(): array
    {
        return [
            'passkeys' => Auth::user()->passkeys()->latest()->get(),
        ];
    }
}; ?>

<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Passkeys') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Sign in without a password using a fingerprint, face, or security key registered on this device.') }}
        </p>
    </header>

    @if ($passkeys->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No passkeys registered yet.') }}</p>
    @else
        <ul class="divide-y divide-gray-200 dark:divide-gray-700 border border-gray-200 dark:border-gray-700 rounded-md">
            @foreach ($passkeys as $passkey)
                <li wire:key="passkey-{{ $passkey->id }}" class="flex items-center justify-between gap-3 px-4 py-3">
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $passkey->name }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $passkey->authenticator ?? __('Unknown authenticator') }}
                            &middot;
                            {{ $passkey->last_used_at ? __('Last used :time', ['time' => $passkey->last_used_at->diffForHumans()]) : __('Never used') }}
                        </div>
                    </div>
                    <button wire:click="delete({{ $passkey->id }})" wire:confirm="{{ __('Remove this passkey?') }}" class="text-sm text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">
                        {{ __('Remove') }}
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    <div
        x-data="{
            name: '',
            loading: false,
            error: null,
            supported: false,
            init() { this.supported = window.Passkeys?.isSupported() ?? false; },
            async register() {
                this.error = null;
                this.loading = true;
                try {
                    await window.Passkeys.register({ name: this.name });
                    this.name = '';
                    $wire.$refresh();
                } catch (e) {
                    if (e?.message === 'Password confirmation required.') {
                        window.location.href = '{{ route('password.confirm') }}?redirect=' + encodeURIComponent(window.location.pathname);
                        return;
                    }
                    if (e?.name !== 'UserCancelledError') {
                        this.error = e?.message ?? '{{ __('Unable to register this passkey.') }}';
                    }
                } finally {
                    this.loading = false;
                }
            },
        }"
    >
        <template x-if="!supported">
            <p class="text-sm text-amber-600 dark:text-amber-400">{{ __('Passkeys are not supported in this browser.') }}</p>
        </template>

        <template x-if="supported">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <x-input-label for="passkey_name" :value="__('Passkey name')" />
                    <x-text-input x-model="name" id="passkey_name" type="text" placeholder="{{ __('e.g. MacBook Pro') }}" class="mt-1 block text-sm" />
                </div>

                <x-secondary-button type="button" x-on:click="register" x-bind:disabled="loading || !name">
                    <span x-show="!loading">{{ __('Add passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering…') }}</span>
                </x-secondary-button>
            </div>
        </template>

        <p x-show="error" x-text="error" x-cloak class="mt-2 text-sm text-red-600 dark:text-red-400"></p>
    </div>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        {{ __('For your security, you may be redirected to confirm your password before a new passkey is added.') }}
    </p>
</section>
