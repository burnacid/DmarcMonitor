<?php

use App\Services\TwoFactor\TwoFactorAuthenticationService;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $code = '';

    public string $password = '';

    public bool $showingRecoveryCodes = false;

    /**
     * Generate a fresh, unconfirmed secret and recovery codes. Nothing is
     * actually protected until confirmTwoFactorAuthentication() verifies a
     * code against it — this just gets the QR code on screen.
     */
    public function enableTwoFactorAuthentication(TwoFactorAuthenticationService $service): void
    {
        $user = Auth::user();

        $user->forceFill([
            'two_factor_secret' => $service->generateSecretKey(),
            'two_factor_recovery_codes' => $service->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function confirmTwoFactorAuthentication(TwoFactorAuthenticationService $service): void
    {
        $user = Auth::user();

        $this->validate([
            'code' => ['required', 'string'],
        ]);

        if (! $user->two_factor_secret || ! $service->verify($user->two_factor_secret, $this->code)) {
            $this->addError('code', __('The provided code is invalid.'));

            return;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        AuditLogger::record(
            action: 'user.two_factor_enabled',
            description: "{$user->name} enabled two-factor authentication",
        );

        $this->code = '';
        $this->showingRecoveryCodes = true;
    }

    public function cancelSetup(): void
    {
        $user = Auth::user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->code = '';
    }

    public function regenerateRecoveryCodes(TwoFactorAuthenticationService $service): void
    {
        $user = Auth::user();

        $user->forceFill([
            'two_factor_recovery_codes' => $service->generateRecoveryCodes(),
        ])->save();

        AuditLogger::record(
            action: 'user.two_factor_recovery_codes_regenerated',
            description: "{$user->name} regenerated their two-factor recovery codes",
        );

        $this->showingRecoveryCodes = true;
    }

    public function disableTwoFactorAuthentication(): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = Auth::user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLogger::record(
            action: 'user.two_factor_disabled',
            description: "{$user->name} disabled two-factor authentication",
        );

        $this->password = '';
        $this->showingRecoveryCodes = false;
    }

    public function with(TwoFactorAuthenticationService $service): array
    {
        $user = Auth::user();

        return [
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'pendingConfirmation' => $user->two_factor_secret && ! $user->hasEnabledTwoFactorAuthentication(),
            'qrCodeSvg' => $user->two_factor_secret ? $service->qrCodeSvg($user, $user->two_factor_secret) : null,
            'manualSetupKey' => $user->two_factor_secret,
            'recoveryCodes' => $user->recoveryCodes(),
        ];
    }
}; ?>

<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Two-Factor Authentication') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Add an extra layer of security using a time-based one-time code from an authenticator app.') }}
        </p>
    </header>

    @if ($enabled)
        <div class="flex items-center gap-2 text-sm text-green-700 dark:text-green-400">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            {{ __('Two-factor authentication is enabled.') }}
        </div>

        @if ($showingRecoveryCodes)
            <div class="rounded-md bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">{{ __('Recovery codes') }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('Store these somewhere safe. Each one can be used once, in place of your authenticator app, if you lose access to it.') }}</p>
                <div class="grid grid-cols-2 gap-1 font-mono text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <div>{{ $recoveryCode }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            <x-secondary-button wire:click="regenerateRecoveryCodes" wire:confirm="{{ __('Regenerate recovery codes? Your existing codes will stop working.') }}">
                {{ __('Regenerate recovery codes') }}
            </x-secondary-button>

            <x-danger-button x-data="" x-on:click.prevent="$dispatch('open-modal', 'disable-two-factor')">
                {{ __('Disable') }}
            </x-danger-button>
        </div>

        <x-modal name="disable-two-factor" :show="$errors->has('password')" focusable>
            <form wire:submit="disableTwoFactorAuthentication" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ __('Disable two-factor authentication?') }}
                </h2>

                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Enter your password to confirm. Your account will no longer require a code at login.') }}
                </p>

                <div class="mt-6">
                    <x-input-label for="tfa_password" value="{{ __('Password') }}" class="sr-only" />
                    <x-text-input wire:model="password" id="tfa_password" name="password" type="password" class="mt-1 block w-3/4" placeholder="{{ __('Password') }}" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end">
                    <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                    <x-danger-button class="ms-3">{{ __('Disable') }}</x-danger-button>
                </div>
            </form>
        </x-modal>
    @elseif ($pendingConfirmation)
        <div class="flex flex-col sm:flex-row gap-6">
            <div class="shrink-0">{!! $qrCodeSvg !!}</div>

            <div class="flex-1 space-y-3">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Scan this with your authenticator app, or enter the key manually:') }}
                </p>
                <code class="block text-xs bg-gray-100 dark:bg-gray-900 rounded px-2 py-1 break-all text-gray-700 dark:text-gray-300">{{ $manualSetupKey }}</code>

                <form wire:submit="confirmTwoFactorAuthentication" class="flex items-end gap-3">
                    <div>
                        <x-input-label for="tfa_code" :value="__('Code')" />
                        <x-text-input wire:model="code" id="tfa_code" type="text" inputmode="numeric" autocomplete="one-time-code" class="mt-1 block text-sm" />
                        <x-input-error :messages="$errors->get('code')" class="mt-2" />
                    </div>
                    <x-primary-button>{{ __('Confirm') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="cancelSetup">{{ __('Cancel') }}</x-secondary-button>
                </form>
            </div>
        </div>
    @else
        <x-secondary-button wire:click="enableTwoFactorAuthentication">
            {{ __('Enable two-factor authentication') }}
        </x-secondary-button>
    @endif
</section>